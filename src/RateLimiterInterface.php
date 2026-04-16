<?php

declare(strict_types=1);

namespace EzPhp\RateLimiter;

/**
 * Interface RateLimiterInterface
 *
 * Contract for all rate limiter backends.
 *
 * Each key tracks a sliding-window hit counter that resets after `$decaySeconds`.
 * The window starts when the first hit is recorded; subsequent hits within the
 * same window do not push the expiry forward.
 *
 * @package EzPhp\RateLimiter
 */
interface RateLimiterInterface
{
    /**
     * Record a hit for the given key and return whether the request is allowed.
     *
     * Returns true  — hit recorded, request is within the limit.
     * Returns false — limit already reached; hit is NOT recorded.
     *
     * @param string $key
     * @param int    $maxAttempts  Maximum allowed hits per window.
     * @param int    $decaySeconds Window length in seconds.
     *
     * @return bool
     */
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool;

    /**
     * Return true if the hit count for the given key is at or above $maxAttempts.
     *
     * @param string $key
     * @param int    $maxAttempts
     *
     * @return bool
     */
    public function tooManyAttempts(string $key, int $maxAttempts): bool;

    /**
     * Return the number of hits still allowed before the key is throttled.
     *
     * @param string $key
     * @param int    $maxAttempts
     *
     * @return int
     */
    public function remainingAttempts(string $key, int $maxAttempts): int;

    /**
     * Clear all recorded hits for the given key.
     *
     * @param string $key
     *
     * @return void
     */
    public function resetAttempts(string $key): void;

    /**
     * Return the number of seconds until the current window resets for the given key.
     * Returns 0 if the key does not exist or has already expired.
     *
     * @param string $key
     *
     * @return int
     */
    public function availableIn(string $key): int;
}
