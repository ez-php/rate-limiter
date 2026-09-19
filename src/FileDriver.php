<?php

declare(strict_types=1);

namespace EzPhp\RateLimiter;

use RuntimeException;

/**
 * Class FileDriver
 *
 * File-backed rate limiter. One file per key holds the `{hits, reset_at}` pair,
 * so counters survive the PHP process — unlike {@see ArrayDriver} — without
 * requiring Redis or a configured cache.
 *
 * **Concurrency.** Every read-modify-write in `attempt()` runs inside an
 * exclusive `flock(LOCK_EX)` held for the whole sequence. This is what makes
 * the driver usable in production: without it two PHP-FPM workers could read
 * the same counter, both be allowed through, and both write back the same
 * value, so the limit would never trip. Read-only methods take a shared lock.
 *
 * Intended for single-host deployments. Locking relies on the filesystem, so a
 * shared network mount across hosts is not a supported configuration — use
 * {@see RedisDriver} for that.
 *
 * @package EzPhp\RateLimiter
 */
final class FileDriver implements RateLimiterInterface
{
    /**
     * @param string $directory Directory holding the counter files. Created on construction.
     *
     * @throws RuntimeException When the directory cannot be created.
     */
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o755, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Cannot create rate limiter directory: {$this->directory}");
        }
    }

    /**
     * Record a hit for the given key and return whether the request is allowed.
     *
     * The check and the increment share one exclusive lock, so the decision and
     * the write cannot be interleaved with another process.
     *
     * @param string $key
     * @param int    $maxAttempts  Maximum allowed hits per window.
     * @param int    $decaySeconds Window length in seconds.
     *
     * @return bool
     * @throws RuntimeException When the counter file cannot be opened or written.
     */
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $path = $this->path($key);
        $fp = fopen($path, 'c+');

        if ($fp === false) {
            throw new RuntimeException("Cannot open rate limiter file: {$path}");
        }

        try {
            flock($fp, LOCK_EX);

            $entry = $this->readLocked($fp);
            $now = time();

            if ($entry === null || $now >= $entry['reset_at']) {
                $entry = ['hits' => 0, 'reset_at' => $now + $decaySeconds];
            }

            if ($entry['hits'] >= $maxAttempts) {
                return false; // refused hits are not counted
            }

            $entry['hits']++;
            $this->writeLocked($fp, $entry, $path);

            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * @param string $key
     * @param int    $maxAttempts
     *
     * @return bool
     */
    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return ($this->read($key)['hits'] ?? 0) >= $maxAttempts;
    }

    /**
     * @param string $key
     * @param int    $maxAttempts
     *
     * @return int
     */
    public function remainingAttempts(string $key, int $maxAttempts): int
    {
        return max(0, $maxAttempts - ($this->read($key)['hits'] ?? 0));
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public function resetAttempts(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            // Losing a race with another process that already removed the file is fine.
            $this->callCapturingWarning(static fn (): bool => unlink($path));
        }
    }

    /**
     * @param string $key
     *
     * @return int
     */
    public function availableIn(string $key): int
    {
        $entry = $this->read($key);

        return $entry === null ? 0 : max(0, $entry['reset_at'] - time());
    }

    /**
     * Delete every counter file whose window has already expired.
     *
     * Nothing calls this automatically. Throttle keys are `throttle:<client-ip>`,
     * so a public endpoint creates one file per unique IP and expired files are
     * only reclaimed if that same IP comes back — an unbounded, untrusted-input
     * driven directory. Sweeping on the hot path would cost a directory scan per
     * request, so this is exposed for the operator to run from `schedule:run`
     * or cron instead.
     *
     * It is deliberately **not** on {@see RateLimiterInterface}: the other
     * drivers have nothing to prune (Redis and the cache expire their own keys,
     * `ArrayDriver` dies with the process).
     *
     * Each candidate is re-checked under `LOCK_EX`, so a file that a concurrent
     * `attempt()` has just restarted is left alone. A file deleted while another
     * process holds it open costs that process its counter — at most one window
     * for one key, which is why expired entries are the only ones removed.
     *
     * @return int Number of files deleted.
     */
    public function prune(): int
    {
        $files = glob($this->directory . '/*.limit');

        if ($files === false) {
            return 0;
        }

        $deleted = 0;

        foreach ($files as $path) {
            if ($this->pruneFile($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Delete one counter file if its window has expired or its contents are unreadable.
     *
     * @param string $path
     *
     * @return bool Whether the file was deleted.
     */
    private function pruneFile(string $path): bool
    {
        $fp = fopen($path, 'r');

        if ($fp === false) {
            return false;
        }

        try {
            flock($fp, LOCK_EX);

            $entry = $this->readLocked($fp);

            if ($entry !== null && time() < $entry['reset_at']) {
                return false;
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        [$removed] = $this->callCapturingWarning(static fn (): bool => unlink($path));

        return $removed;
    }

    /**
     * Counter file path for a key.
     *
     * The key is hashed rather than used directly: throttle keys contain the
     * client IP and arbitrary caller-supplied text, which may include path
     * separators or `..` segments that would otherwise escape the directory.
     *
     * @param string $key
     *
     * @return string
     */
    private function path(string $key): string
    {
        return $this->directory . '/' . sha1($key) . '.limit';
    }

    /**
     * Read a key's entry under a shared lock, honouring window expiry.
     *
     * @param string $key
     *
     * @return array{hits: int, reset_at: int}|null Null when absent or expired.
     */
    private function read(string $key): ?array
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        $fp = fopen($path, 'r');

        if ($fp === false) {
            return null;
        }

        try {
            flock($fp, LOCK_SH);

            $entry = $this->readLocked($fp);

            if ($entry === null || time() >= $entry['reset_at']) {
                return null;
            }

            return $entry;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Decode the entry from an already-locked handle.
     *
     * @param resource $fp
     *
     * @return array{hits: int, reset_at: int}|null
     */
    private function readLocked(mixed $fp): ?array
    {
        rewind($fp);
        $raw = stream_get_contents($fp);

        if ($raw === false || $raw === '') {
            return null;
        }

        /** @var mixed $data */
        $data = json_decode($raw, true);

        if (!is_array($data) || !isset($data['hits'], $data['reset_at'])) {
            return null;
        }

        if (!is_int($data['hits']) || !is_int($data['reset_at'])) {
            return null;
        }

        return ['hits' => $data['hits'], 'reset_at' => $data['reset_at']];
    }

    /**
     * Overwrite the entry on an already-locked handle.
     *
     * @param resource                        $fp
     * @param array{hits: int, reset_at: int} $entry
     * @param string                          $path Used for the error message only.
     *
     * @return void
     * @throws RuntimeException When the write fails.
     */
    private function writeLocked(mixed $fp, array $entry, string $path): void
    {
        ftruncate($fp, 0);
        rewind($fp);

        if (fwrite($fp, (string) json_encode($entry)) === false) {
            throw new RuntimeException("Rate limiter write failed: {$path}");
        }

        fflush($fp);
    }

    /**
     * Run a stream/filesystem call with PHP warnings converted into a returned message
     * instead of being emitted (replaces the `@` operator, which hides the reason).
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return array{0: T, 1: string|null} The call's result and the captured warning message, if any.
     */
    private function callCapturingWarning(callable $fn): array
    {
        $warning = null;

        set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
            $warning = $errstr;

            return true;
        }, E_WARNING);

        try {
            $result = $fn();
        } finally {
            restore_error_handler();
        }

        return [$result, $warning];
    }
}
