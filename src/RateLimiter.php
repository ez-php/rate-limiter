<?php

declare(strict_types=1);

namespace EzPhp\RateLimiter;

/**
 * Class RateLimiter
 *
 * Static facade for the rate limiter.
 *
 * Usage:
 *   RateLimiter::attempt('login:'.$ip, 5, 60)   // bool — true = allowed, false = throttled
 *   RateLimiter::tooManyAttempts('login:'.$ip, 5)  // bool
 *   RateLimiter::remainingAttempts('login:'.$ip, 5) // int
 *   RateLimiter::resetAttempts('login:'.$ip)        // void
 *
 * The facade is backed by a managed singleton. RateLimiterServiceProvider sets
 * the instance during boot(). Without a service provider the facade falls back
 * to an in-memory ArrayDriver.
 *
 * @package EzPhp\RateLimiter
 */
final class RateLimiter
{
    private static ?self $instance = null;

    /**
     * RateLimiter Constructor
     *
     * @param RateLimiterInterface $driver
     */
    public function __construct(private readonly RateLimiterInterface $driver)
    {
    }

    // ─── Static instance management ──────────────────────────────────────────

    /**
     * @param self $instance
     *
     * @return void
     */
    public static function setInstance(self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(new ArrayDriver());
        }

        return self::$instance;
    }

    /**
     * Reset the static instance (useful in tests).
     *
     * @return void
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    // ─── Static facade ────────────────────────────────────────────────────────

    /**
     * Record a hit and return whether the request is allowed.
     *
     * @param string $key
     * @param int    $maxAttempts
     * @param int    $decaySeconds
     *
     * @return bool
     */
    public static function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        return self::getInstance()->driver->attempt($key, $maxAttempts, $decaySeconds);
    }

    /**
     * Return true if the hit count is at or above $maxAttempts.
     *
     * @param string $key
     * @param int    $maxAttempts
     *
     * @return bool
     */
    public static function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return self::getInstance()->driver->tooManyAttempts($key, $maxAttempts);
    }

    /**
     * Return the number of hits still allowed before the key is throttled.
     *
     * @param string $key
     * @param int    $maxAttempts
     *
     * @return int
     */
    public static function remainingAttempts(string $key, int $maxAttempts): int
    {
        return self::getInstance()->driver->remainingAttempts($key, $maxAttempts);
    }

    /**
     * Clear all recorded hits for the given key.
     *
     * @param string $key
     *
     * @return void
     */
    public static function resetAttempts(string $key): void
    {
        self::getInstance()->driver->resetAttempts($key);
    }
}
