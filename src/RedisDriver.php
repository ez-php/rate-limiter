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

        try {
            $hits = $this->redis->incr($key);

            if ($hits === 1 || $hits === false) {
                $this->redis->expire($key, $decaySeconds);
            }
        } catch (\RedisException) {
            // Fail open: a Redis outage should not turn into a site-wide 500
            // via ThrottleMiddleware. Allowing the request through un-throttled
            // is the safer failure mode for a rate limiter (as opposed to
            // fail-closed, which would block all traffic on a Redis hiccup).
            return true;
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
        try {
            /** @var string|false $raw */
            $raw = $this->redis->get($key);
        } catch (\RedisException) {
            // Fail open (see attempt()): treat a Redis outage as zero hits
            // recorded rather than propagating the exception through
            // tooManyAttempts()/remainingAttempts() into ThrottleMiddleware.
            return 0;
        }

        return $raw !== false ? (int) $raw : 0;
    }
}
