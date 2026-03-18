<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Cache\ArrayDriver as CacheArrayDriver;
use EzPhp\RateLimiter\CacheDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Class CacheDriverTest
 *
 * Uses ez-php/cache's ArrayDriver as the backing store so no external
 * infrastructure is required.
 *
 * @package Tests
 */
#[CoversClass(CacheDriver::class)]
#[UsesClass(CacheArrayDriver::class)]
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
}
