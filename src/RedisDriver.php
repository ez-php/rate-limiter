<?php

declare(strict_types=1);

namespace EzPhp\RateLimiter;

use Redis;
use RuntimeException;

/**
 * Class RedisDriver
 *
 * Rate limiter backed by Redis, fixed window. attempt() runs one Lua script, so
 * check, INCR and EXPIRE are atomic: concurrent requests can never push the
 * counter past $maxAttempts, and the TTL is set in the same step as the first
 * hit (a key found without a TTL gets one too, so it can't lock out forever).
 * Rejected attempts are not counted. Later hits do not reset the window.
 *
 * Requires the PHP `ext-redis` extension.
 *
 * @package EzPhp\RateLimiter
 */
final readonly class RedisDriver implements RateLimiterInterface
{
    /**
     * KEYS[1] = counter key, ARGV[1] = max attempts, ARGV[2] = decay seconds.
     * Returns 1 when the hit was counted, 0 when the limit is already reached.
     */
    private const string ATTEMPT_SCRIPT = <<<'LUA'
        local hits = tonumber(redis.call('GET', KEYS[1]) or '0')
        if hits >= tonumber(ARGV[1]) then
            return 0
        end
        redis.call('INCR', KEYS[1])
        if redis.call('TTL', KEYS[1]) < 0 then
            redis.call('EXPIRE', KEYS[1], ARGV[2])
        end
        return 1
        LUA;

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
        try {
            $allowed = $this->redis->eval(self::ATTEMPT_SCRIPT, [$key, $maxAttempts, $decaySeconds], 1);
        } catch (\RedisException) {
            // Fail open: a Redis outage should not turn into a site-wide 500
            // via ThrottleMiddleware. Allowing the request through un-throttled
            // is the safer failure mode for a rate limiter (as opposed to
            // fail-closed, which would block all traffic on a Redis hiccup).
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
