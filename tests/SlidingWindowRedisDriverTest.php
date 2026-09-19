<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\RateLimiter\SlidingWindowRedisDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use Redis;

/**
 * Class SlidingWindowRedisDriverTest
 *
 * Requires a live Redis instance (available via Docker).
 * Tests are skipped automatically when ext-redis is not loaded.
 *
 * Uses Redis database 2, same as RedisDriverTest — the two test classes
 * never run concurrently (PHPUnit runs a suite sequentially), and each
 * flushes the database in setUp/tearDown.
 *
 * @package Tests
 */
#[CoversClass(SlidingWindowRedisDriver::class)]
final class SlidingWindowRedisDriverTest extends TestCase
{
    private SlidingWindowRedisDriver $driver;

    private Redis $redis;

    private bool $redisConnected = false;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not available.');
        }

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        $this->redis = new Redis();

        try {
            $connected = @$this->redis->connect($host, $port);
        } catch (\RedisException) {
            $this->markTestSkipped("Redis is not available at {$host}:{$port}.");
        }

        if (!$connected) {
            $this->markTestSkipped("Redis is not available at {$host}:{$port}.");
        }

        $this->redisConnected = true;
        $this->redis->select(2);
        $this->redis->flushDB();

        $this->driver = new SlidingWindowRedisDriver($this->redis);
    }

    protected function tearDown(): void
    {
        if ($this->redisConnected) {
            $this->redis->flushDB();
        }
    }

    // ── attempt ───────────────────────────────────────────────────────────────

    public function test_attempt_returns_true_when_under_limit(): void
    {
        $this->assertTrue($this->driver->attempt('key', 3, 60));
    }

    public function test_attempt_returns_true_up_to_max(): void
    {
        $this->assertTrue($this->driver->attempt('key', 3, 60));
        $this->assertTrue($this->driver->attempt('key', 3, 60));
        $this->assertTrue($this->driver->attempt('key', 3, 60));
    }

    public function test_attempt_returns_false_when_limit_reached(): void
    {
        $this->driver->attempt('key', 2, 60);
        $this->driver->attempt('key', 2, 60);

        $this->assertFalse($this->driver->attempt('key', 2, 60));
    }

    public function test_attempt_does_not_count_rejected_hits(): void
    {
        $this->driver->attempt('key', 1, 60);
        $this->driver->attempt('key', 1, 60); // rejected, must not be recorded

        $this->assertSame(1, $this->driver->remainingAttempts('key', 2));
    }

    public function test_attempt_expires_old_entries_out_of_the_window(): void
    {
        // A hit recorded just outside the window (in the past) must not count.
        $past = microtime(true) - 5;
        $this->redis->zAdd('key', $past, 'stale-hit');

        // Window is 1 second — the stale hit above is already outside it.
        $this->assertTrue($this->driver->attempt('key', 1, 1));
        $this->assertSame(1, $this->driver->remainingAttempts('key', 2));
    }

    // ── tooManyAttempts ──────────────────────────────────────────────────────

    public function test_too_many_attempts_false_when_under_limit(): void
    {
        $this->driver->attempt('key', 3, 60);

        $this->assertFalse($this->driver->tooManyAttempts('key', 3));
    }

    public function test_too_many_attempts_true_when_at_limit(): void
    {
        $this->driver->attempt('key', 2, 60);
        $this->driver->attempt('key', 2, 60);

        $this->assertTrue($this->driver->tooManyAttempts('key', 2));
    }

    // ── remainingAttempts ────────────────────────────────────────────────────

    public function test_remaining_attempts_decrements_per_hit(): void
    {
        $this->driver->attempt('key', 3, 60);

        $this->assertSame(2, $this->driver->remainingAttempts('key', 3));
    }

    public function test_remaining_attempts_never_negative(): void
    {
        $this->driver->attempt('key', 1, 60);
        $this->driver->attempt('key', 1, 60);

        $this->assertSame(0, $this->driver->remainingAttempts('key', 1));
    }

    // ── resetAttempts ────────────────────────────────────────────────────────

    public function test_reset_attempts_clears_the_key(): void
    {
        $this->driver->attempt('key', 1, 60);
        $this->driver->resetAttempts('key');

        $this->assertTrue($this->driver->attempt('key', 1, 60));
    }

    public function test_reset_attempts_is_noop_on_unknown_key(): void
    {
        $this->driver->resetAttempts('never-used');

        $this->addToAssertionCount(1);
    }

    // ── availableIn ──────────────────────────────────────────────────────────

    public function test_available_in_is_positive_after_a_hit(): void
    {
        $this->driver->attempt('key', 1, 60);

        $this->assertGreaterThan(0, $this->driver->availableIn('key'));
        $this->assertLessThanOrEqual(60, $this->driver->availableIn('key'));
    }

    public function test_available_in_is_zero_for_unknown_key(): void
    {
        $this->assertSame(0, $this->driver->availableIn('never-used'));
    }

    // ── key isolation ────────────────────────────────────────────────────────

    public function test_keys_are_isolated(): void
    {
        $this->driver->attempt('a', 1, 60);

        $this->assertTrue($this->driver->attempt('b', 1, 60));
        $this->assertFalse($this->driver->attempt('a', 1, 60));
    }
}
