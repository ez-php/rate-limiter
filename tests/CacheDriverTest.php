<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Cache\ArrayDriver as CacheArrayDriver;
use EzPhp\RateLimiter\CacheDriver;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class CacheDriverTest
 *
 * Uses ez-php/cache's ArrayDriver as the backing store so no external
 * infrastructure is required.
 *
 * @package Tests
 */
#[CoversClass(CacheDriver::class)]
final class CacheDriverTest extends TestCase
{
    private CacheDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new CacheDriver(new CacheArrayDriver());
    }

    // ── attempt ───────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_attempt_returns_true_when_under_limit(): void
    {
        $this->assertTrue($this->driver->attempt('key', 3, 60));
    }

    /**
     * @return void
     */
    public function test_attempt_returns_true_up_to_max(): void
    {
        $this->assertTrue($this->driver->attempt('key', 3, 60));
        $this->assertTrue($this->driver->attempt('key', 3, 60));
        $this->assertTrue($this->driver->attempt('key', 3, 60));
    }

    /**
     * @return void
     */
    public function test_attempt_returns_false_when_limit_reached(): void
    {
        $this->driver->attempt('key', 2, 60);
        $this->driver->attempt('key', 2, 60);

        $this->assertFalse($this->driver->attempt('key', 2, 60));
    }

    // ── tooManyAttempts ───────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_too_many_attempts_false_on_fresh_key(): void
    {
        $this->assertFalse($this->driver->tooManyAttempts('key', 5));
    }

    /**
     * @return void
     */
    public function test_too_many_attempts_true_after_limit_reached(): void
    {
        $this->driver->attempt('key', 2, 60);
        $this->driver->attempt('key', 2, 60);

        $this->assertTrue($this->driver->tooManyAttempts('key', 2));
    }

    // ── remainingAttempts ─────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_remaining_attempts_equals_max_on_fresh_key(): void
    {
        $this->assertSame(5, $this->driver->remainingAttempts('key', 5));
    }

    /**
     * @return void
     */
    public function test_remaining_attempts_decrements_on_each_attempt(): void
    {
        $this->driver->attempt('key', 5, 60);
        $this->assertSame(4, $this->driver->remainingAttempts('key', 5));

        $this->driver->attempt('key', 5, 60);
        $this->assertSame(3, $this->driver->remainingAttempts('key', 5));
    }

    /**
     * @return void
     */
    public function test_remaining_attempts_is_zero_when_throttled(): void
    {
        $this->driver->attempt('key', 2, 60);
        $this->driver->attempt('key', 2, 60);

        $this->assertSame(0, $this->driver->remainingAttempts('key', 2));
    }

    // ── resetAttempts ─────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_reset_clears_counter(): void
    {
        $this->driver->attempt('key', 3, 60);
        $this->driver->attempt('key', 3, 60);
        $this->driver->resetAttempts('key');

        $this->assertSame(3, $this->driver->remainingAttempts('key', 3));
        $this->assertFalse($this->driver->tooManyAttempts('key', 3));
    }

    // ── availableIn ───────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_available_in_returns_zero_for_unknown_key(): void
    {
        $this->assertSame(0, $this->driver->availableIn('key'));
    }

    /**
     * @return void
     */
    public function test_available_in_returns_positive_seconds_after_first_hit(): void
    {
        $this->driver->attempt('key', 5, 60);

        $availableIn = $this->driver->availableIn('key');
        $this->assertGreaterThan(0, $availableIn);
        $this->assertLessThanOrEqual(60, $availableIn);
    }

    /**
     * @return void
     */
    public function test_available_in_returns_zero_after_reset(): void
    {
        $this->driver->attempt('key', 5, 60);
        $this->driver->resetAttempts('key');

        $this->assertSame(0, $this->driver->availableIn('key'));
    }

    // ── key isolation ─────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_different_keys_are_independent(): void
    {
        $this->driver->attempt('a', 2, 60);
        $this->driver->attempt('a', 2, 60);

        $this->assertFalse($this->driver->attempt('a', 2, 60));
        $this->assertTrue($this->driver->attempt('b', 2, 60));
    }

    // ── atomicity ─────────────────────────────────────────────────────────────

    /**
     * Runs attempt() from several processes over a shared ez-php/cache
     * FileDriver: the per-key lock must keep the total at maxAttempts.
     *
     * @return void
     */
    public function test_concurrent_attempts_never_exceed_max(): void
    {
        $dir = sys_get_temp_dir() . '/ez-php-rl-cache-' . bin2hex(random_bytes(6));
        // Created up front so the children don't race on mkdir() in the cache FileDriver.
        mkdir($dir, 0o700);

        try {
            $allowed = RateLimiterConcurrency::allowedAttempts(
                sprintf('new \EzPhp\RateLimiter\CacheDriver(new \EzPhp\Cache\FileDriver(%s))', var_export($dir, true)),
                'concurrent',
                10,
                12,
                5,
            );
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }

        $this->assertSame(10, $allowed);
    }
}
