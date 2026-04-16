<?php

declare(strict_types=1);

namespace EzPhp\RateLimiter;

use EzPhp\Cache\CacheInterface;

/**
 * Class CacheDriver
 *
 * Rate limiter backed by any `ez-php/cache` driver (Array, File, Redis).
 * Each counter is stored as an array containing the hit count and the
 * absolute reset timestamp. The cache TTL is computed as remaining seconds
 * to the reset point so the entry expires together with the window.
 *
 * @package EzPhp\RateLimiter
 */
final readonly class CacheDriver implements RateLimiterInterface
{
    /**
     * CacheDriver Constructor
     *
     * @param CacheInterface $cache
     */
    public function __construct(private CacheInterface $cache)
    {
    }

    /**
     * @param string $key
     * @param int    $maxAttempts
     * @param int    $decaySeconds
     *
     * @return bool
     */
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        if ($this->tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        $entry = $this->readEntry($key);

        if ($entry === null) {
            $entry = ['hits' => 0, 'reset_at' => time() + $decaySeconds];
        }

        $entry['hits']++;
        $this->cache->set($key, $entry, max(1, $entry['reset_at'] - time()));

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
        $entry = $this->readEntry($key);

        return $entry !== null && $entry['hits'] >= $maxAttempts;
    }

    /**
     * @param string $key
     * @param int    $maxAttempts
     *
     * @return int
     */
    public function remainingAttempts(string $key, int $maxAttempts): int
    {
        $entry = $this->readEntry($key);
        $hits = $entry !== null ? $entry['hits'] : 0;

        return max(0, $maxAttempts - $hits);
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public function resetAttempts(string $key): void
    {
        $this->cache->forget($key);
    }

    /**
     * @param string $key
     *
     * @return int
     */
    public function availableIn(string $key): int
    {
        $entry = $this->readEntry($key);

        return $entry !== null ? max(0, $entry['reset_at'] - time()) : 0;
    }

    /**
     * @param string $key
     *
     * @return array{hits: int, reset_at: int}|null
     */
    private function readEntry(string $key): ?array
    {
        $raw = $this->cache->get($key);

        if (!is_array($raw) || !isset($raw['hits'], $raw['reset_at'])) {
            return null;
        }

        /** @var array{hits: int, reset_at: int} $raw */
        return $raw;
    }
}
