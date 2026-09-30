<?php

declare(strict_types=1);

namespace EzPhp\RateLimiter;

use Redis;
use RuntimeException;

/**
 * Class SlidingWindowRedisDriver
 *
 * Rate limiter backed by a Redis sorted set, one member per hit (score = hit
 * timestamp). `attempt()` runs one Lua script that removes members older
 * than the current window (`ZREMRANGEBYSCORE ... -inf (now - decaySeconds)`),
 * checks `ZCARD` against the limit, adds the new member and refreshes the
 * TTL — atomically, so concurrent requests can never exceed the limit. A true
 * sliding window, unlike `RedisDriver`'s fixed window that resets in one block.
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
     * KEYS[1] = sorted-set key; ARGV[1] = window start, ARGV[2] = now (score),
     * ARGV[3] = member, ARGV[4] = max attempts, ARGV[5] = decay seconds.
     * Returns 1 when the hit was recorded, 0 when the limit is already reached.
     */
    private const string ATTEMPT_SCRIPT = <<<'LUA'
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])
        if redis.call('ZCARD', KEYS[1]) >= tonumber(ARGV[4]) then
            return 0
        end
        redis.call('ZADD', KEYS[1], ARGV[2], ARGV[3])
        redis.call('EXPIRE', KEYS[1], ARGV[5])
        return 1
        LUA;

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
        $now = microtime(true);
        $member = sprintf('%.6F', $now) . ':' . bin2hex(random_bytes(4));

        try {
            $allowed = $this->redis->eval(self::ATTEMPT_SCRIPT, [
                $key,
                sprintf('%.6F', $now - $decaySeconds),
                sprintf('%.6F', $now),
                $member,
                $maxAttempts,
                $decaySeconds,
            ], 1);
        } catch (\RedisException) {
            // Fail open — see RedisDriver::attempt() for the same reasoning.
            return true;
        }

        // eval() returns false when the script itself fails; fail open as above.
        return $allowed !== 0;
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
            $count = $this->redis->zCard($key);
        } catch (\RedisException) {
            // Fail open (see attempt()).
            return 0;
        }

        return is_int($count) ? $count : 0;
    }
}
