<?php

declare(strict_types=1);

namespace Tests\RateLimiter;

use EzPhp\RateLimiter\ArrayDriver;
use EzPhp\RateLimiter\RateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Class RateLimiterTest
 *
 * @package Tests\RateLimiter
 */
#[CoversClass(RateLimiter::class)]
#[UsesClass(ArrayDriver::class)]
final class RateLimiterTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::setInstance(new RateLimiter(new ArrayDriver()));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        RateLimiter::resetInstance();
        parent::tearDown();
    }

    // ─── Instance management ─────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_getInstance_returns_same_instance(): void
    {
        $a = RateLimiter::getInstance();
        $b = RateLimiter::getInstance();

        $this->assertSame($a, $b);
    }

    /**
     * @return void
     */
    public function test_setInstance_replaces_singleton(): void
    {
        $driver = new ArrayDriver();
        $instance = new RateLimiter($driver);

        RateLimiter::setInstance($instance);

        $this->assertSame($instance, RateLimiter::getInstance());
    }

    /**
     * @return void
     */
    public function test_resetInstance_clears_singleton(): void
    {
        RateLimiter::resetInstance();

        $this->expectException(RuntimeException::class);
        RateLimiter::getInstance();
    }

    /**
     * @return void
     */
    public function test_getInstance_throws_when_no_instance_is_set(): void
    {
        RateLimiter::resetInstance();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RateLimiterServiceProvider');
        RateLimiter::getInstance();
    }

    /**
     * @return void
     */
    public function test_static_calls_throw_instead_of_silently_using_an_in_memory_driver(): void
    {
        RateLimiter::resetInstance();

        $this->expectException(RuntimeException::class);
        RateLimiter::attempt('login:203.0.113.9', 5, 60);
    }

    // ─── Static facade ───────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_attempt_returns_true_within_limit(): void
    {
        $this->assertTrue(RateLimiter::attempt('key', 3, 60));
        $this->assertTrue(RateLimiter::attempt('key', 3, 60));
        $this->assertTrue(RateLimiter::attempt('key', 3, 60));
    }

    /**
     * @return void
     */
    public function test_attempt_returns_false_when_limit_exceeded(): void
    {
        RateLimiter::attempt('key', 1, 60);

        $this->assertFalse(RateLimiter::attempt('key', 1, 60));
    }

    /**
     * @return void
     */
    public function test_tooManyAttempts_returns_false_within_limit(): void
    {
        RateLimiter::attempt('key', 3, 60);

        $this->assertFalse(RateLimiter::tooManyAttempts('key', 3));
    }

    /**
     * @return void
     */
    public function test_tooManyAttempts_returns_true_at_limit(): void
    {
        RateLimiter::attempt('key', 1, 60);

        $this->assertTrue(RateLimiter::tooManyAttempts('key', 1));
    }

    /**
     * @return void
     */
    public function test_remainingAttempts_decreases_with_each_hit(): void
    {
        $this->assertSame(3, RateLimiter::remainingAttempts('key', 3));

        RateLimiter::attempt('key', 3, 60);
        $this->assertSame(2, RateLimiter::remainingAttempts('key', 3));

        RateLimiter::attempt('key', 3, 60);
        $this->assertSame(1, RateLimiter::remainingAttempts('key', 3));
    }

    /**
     * @return void
     */
    public function test_resetAttempts_clears_counter(): void
    {
        RateLimiter::attempt('key', 1, 60);
        $this->assertTrue(RateLimiter::tooManyAttempts('key', 1));

        RateLimiter::resetAttempts('key');
        $this->assertFalse(RateLimiter::tooManyAttempts('key', 1));
    }

    /**
     * @return void
     */
    public function test_availableIn_returns_zero_for_unknown_key(): void
    {
        $this->assertSame(0, RateLimiter::availableIn('unknown'));
    }

    /**
     * @return void
     */
    public function test_availableIn_returns_positive_seconds_after_hit(): void
    {
        RateLimiter::attempt('key', 5, 60);

        $availableIn = RateLimiter::availableIn('key');
        $this->assertGreaterThan(0, $availableIn);
        $this->assertLessThanOrEqual(60, $availableIn);
    }

    // ─── setInstance wires driver ─────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_setInstance_wires_custom_driver(): void
    {
        $driver = new ArrayDriver();
        RateLimiter::setInstance(new RateLimiter($driver));

        RateLimiter::attempt('x', 1, 60);

        $this->assertTrue(RateLimiter::tooManyAttempts('x', 1));
    }
}
