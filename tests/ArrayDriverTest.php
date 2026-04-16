<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\RateLimiter\ArrayDriver;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class ArrayDriverTest
 *
 * @package Tests
 */
#[CoversClass(ArrayDriver::class)]
final class ArrayDriverTest extends TestCase
{
    private ArrayDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new ArrayDriver();
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

    /**
     * @return void
     */
    public function test_attempt_does_not_count_when_throttled(): void
    {
        $this->driver->attempt('key', 1, 60);
        $this->driver->attempt('key', 1, 60); // throttled, not counted

        $this->assertSame(1, 1 - $this->driver->remainingAttempts('key', 1)); // hits = 1
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

    /**
     * @return void
     */
    public function test_reset_on_unknown_key_is_noop(): void
    {
        $this->driver->resetAttempts('nonexistent');

        $this->assertSame(5, $this->driver->remainingAttempts('nonexistent', 5));
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
}
