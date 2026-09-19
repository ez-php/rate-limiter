<?php

declare(strict_types=1);

namespace EzPhp\RateLimiter;

use Redis;
use RuntimeException;

/**
 * Class SlidingWindowRedisDriver
 *
 * Rate limiter backed by a Redis sorted set, one member per hit (score = hit
 * timestamp). `attempt()` first removes members older than the current
 * window (`ZREMRANGEBYSCORE ... -inf (now - decaySeconds)`), then checks
 * `ZCARD` against the limit before adding a new member — a true sliding
 * window, unlike `RedisDriver`'s fixed window that resets in one block.
 *
 * `tooManyAttempts()`/`remainingAttempts()`/`availableIn()` do not receive
 * `$decaySeconds` (per RateLimiterInterface) and therefore do not re-prune;
 * they report against whatever `attempt()` last pruned. This matches how
 * ThrottleMiddleware actually calls them — immediately after `attempt()` —
 * and is the same constraint FixedWindow-style drivers already have.
 *
 * Requires the PHP `ext-redis` extension.
 *
 * @package EzPhp\RateLimiter
 */
final readonly class SlidingWindowRedisDriver implements RateLimiterInterface
{
    /**
     * SlidingWindowRedisDriver Constructor
     *
     * @param Redis $redis
     *
     * @throws RuntimeException When ext-redis is not loaded.
     */
    public function __construct(private Redis $redis)
    {
        if (!extension_loaded('redis')) {
            throw new RuntimeException('The ext-redis extension is required to use SlidingWindowRedisDriver.');
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
        try {
            $this->pruneExpired($key, $decaySeconds);

            if ($this->currentHits($key) >= $maxAttempts) {
                return false;
            }

            $now = microtime(true);
            $member = $now . ':' . bin2hex(random_bytes(4));

            $this->redis->zAdd($key, $now, $member);
            $this->redis->expire($key, $decaySeconds);
        } catch (\RedisException) {
            // Fail open — see RedisDriver::attempt() for the same reasoning.
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
     * Remove members whose hit timestamp is older than the current window.
     *
     * @param string $key
     * @param int    $decaySeconds
     *
     * @return void
     */
    private function pruneExpired(string $key, int $decaySeconds): void
    {
        $windowStart = microtime(true) - $decaySeconds;

        $this->redis->zRemRangeByScore($key, '-inf', (string) $windowStart);
    }

    /**
     * @param string $key
     *
     * @return int
     */
    private function currentHits(string $key): int
    {
        try {
            $count = $this->redis->zCard($key);
        } catch (\RedisException) {
            // Fail open (see attempt()).
            return 0;
        }

        return is_int($count) ? $count : 0;
    }
}
