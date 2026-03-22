<?php

declare(strict_types=1);

namespace EzPhp\RateLimiter;

/**
 * Class ArrayDriver
 *
 * In-process rate limiter backed by a plain PHP array.
 * State is lost when the PHP process ends; suitable for tests and
 * single-process environments where persistence is not required.
 *
 * **Single-process / single-thread only.**
 * The store is a plain PHP array with no atomic operations. Under concurrent
 * execution (e.g. PHP-FPM with overlapping requests) two workers can read the
 * same counter value simultaneously, both be allowed through, and then both
 * write back the same incremented value — effectively counting one hit instead
 * of two. RedisDriver (INCR) and CacheDriver provide atomic operations and are
 * safe for concurrent use.
 *
 * @package EzPhp\RateLimiter
 */
final class ArrayDriver implements RateLimiterInterface
{
    /**
     * @var array<string, array{hits: int, reset_at: int}>
     */
    private array $store = [];

    /**
     * @param string $key
     * @param int    $maxAttempts
     * @param int    $decaySeconds
     *
     * @return bool
     */
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $this->cleanIfExpired($key);

        if ($this->tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        if (!isset($this->store[$key])) {
            $this->store[$key] = ['hits' => 0, 'reset_at' => time() + $decaySeconds];
        }

        $this->store[$key]['hits']++;

        return true;
    }

    /**
     * @param string $key
     * @param int    $maxAttempts
     *
     * @return bool
     */
    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        $this->cleanIfExpired($key);

        return ($this->store[$key]['hits'] ?? 0) >= $maxAttempts;
    }

    /**
     * @param string $key
     * @param int    $maxAttempts
     *
     * @return int
     */
    public function remainingAttempts(string $key, int $maxAttempts): int
    {
        $this->cleanIfExpired($key);

        return max(0, $maxAttempts - ($this->store[$key]['hits'] ?? 0));
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public function resetAttempts(string $key): void
    {
        unset($this->store[$key]);
    }

    /**
     * @param string $key
     *
     * @return void
     */
    private function cleanIfExpired(string $key): void
    {
        if (isset($this->store[$key]) && time() >= $this->store[$key]['reset_at']) {
            unset($this->store[$key]);
        }
    }
}
