<?php

declare(strict_types=1);

namespace EzPhp\RateLimiter;

use Redis;
use RuntimeException;

/**
 * Class RedisDriver
 *
 * Rate limiter backed by Redis. Uses INCR + EXPIRE: the counter key is set on
 * the first hit with an expiry of $decaySeconds. Subsequent hits increment the
 * counter without resetting the window.
 *
 * Requires the PHP `ext-redis` extension.
 *
 * @package EzPhp\RateLimiter
 */
final readonly class RedisDriver implements RateLimiterInterface
{
    /**
     * RedisDriver Constructor
     *
     * @param Redis $redis
     *
     * @throws RuntimeException When ext-redis is not loaded.
     */
    public function __construct(private Redis $redis)
    {
        if (!extension_loaded('redis')) {
            throw new RuntimeException('The ext-redis extension is required to use RedisDriver.');
        }
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

        $hits = $this->redis->incr($key);

        if ($hits === 1 || $hits === false) {
            $this->redis->expire($key, $decaySeconds);
        }

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
        return $this->currentHits($key) >= $maxAttempts;
    }

    /**
     * @param string $key
     * @param int    $maxAttempts
     *
     * @return int
     */
    public function remainingAttempts(string $key, int $maxAttempts): int
    {
        return max(0, $maxAttempts - $this->currentHits($key));
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public function resetAttempts(string $key): void
    {
        $this->redis->del($key);
    }

    /**
     * @param string $key
     *
     * @return int
     */
    public function availableIn(string $key): int
    {
        $ttl = $this->redis->ttl($key);

        return is_int($ttl) && $ttl > 0 ? $ttl : 0;
    }

    /**
     * @param string $key
     *
     * @return int
     */
    private function currentHits(string $key): int
    {
        /** @var string|false $raw */
        $raw = $this->redis->get($key);

        return $raw !== false ? (int) $raw : 0;
    }
}
