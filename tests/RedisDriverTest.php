<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\RateLimiter\RedisDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use Redis;

/**
 * Class RedisDriverTest
 *
 * Requires a live Redis instance (available via Docker).
 * Tests are skipped automatically when ext-redis is not loaded.
 *
 * Uses Redis database 2 to avoid colliding with application data.
 *
 * @package Tests
 */
#[CoversClass(RedisDriver::class)]
final class RedisDriverTest extends TestCase
{
    private RedisDriver $driver;

    private Redis $redis;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not available.');
        }

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        $this->redis = new Redis();
        $this->redis->connect($host, $port);
        $this->redis->select(2);
        $this->redis->flushDB();

        $this->driver = new RedisDriver($this->redis);
    }

    protected function tearDown(): void
    {
        if (extension_loaded('redis')) {
            $this->redis->flushDB();
        }
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
