<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\RateLimiter\FileDriver;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class RateLimiterFileDriverTest
 *
 * Named with a `RateLimiter` prefix, not `FileDriverTest`: the root phpunit.xml
 * aggregates every module's tests and they all share the `Tests\` namespace, so
 * a plain `Tests\FileDriverTest` collides with `modules/logging`'s at load time
 * (fatal, not a test failure). Do not "tidy" the prefix away.
 *
 * Mirrors the contract surface of ArrayDriverTest so driver parity is visible,
 * plus file-specific cases (directory creation, persistence across instances).
 *
 * Uses a temp directory cleaned up in tearDown — no external infrastructure.
 *
 * @package Tests
 */
#[CoversClass(FileDriver::class)]
final class RateLimiterFileDriverTest extends TestCase
{
    private string $dir;

    private FileDriver $driver;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ez-php-rl-' . bin2hex(random_bytes(6));
        $this->driver = new FileDriver($this->dir);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
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
        for ($i = 0; $i < 3; $i++) {
            $this->driver->attempt('key', 3, 60);
        }

        $this->assertFalse($this->driver->attempt('key', 3, 60));
    }

    public function test_attempt_does_not_count_when_throttled(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->driver->attempt('key', 3, 60);
        }

        $this->driver->attempt('key', 3, 60);
        $this->driver->attempt('key', 3, 60);

        // A refused hit must not advance the counter.
        $this->assertSame(0, $this->driver->remainingAttempts('key', 3));
        $this->assertTrue($this->driver->tooManyAttempts('key', 3));
    }

    // ── tooManyAttempts ───────────────────────────────────────────────────────

    public function test_too_many_attempts_false_on_fresh_key(): void
    {
        $this->assertFalse($this->driver->tooManyAttempts('fresh', 3));
    }

    public function test_too_many_attempts_true_after_limit_reached(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->driver->attempt('key', 2, 60);
        }

        $this->assertTrue($this->driver->tooManyAttempts('key', 2));
    }

    // ── remainingAttempts ─────────────────────────────────────────────────────

    public function test_remaining_attempts_equals_max_on_fresh_key(): void
    {
        $this->assertSame(5, $this->driver->remainingAttempts('fresh', 5));
    }

    public function test_remaining_attempts_decrements_on_each_attempt(): void
    {
        $this->driver->attempt('key', 5, 60);
        $this->assertSame(4, $this->driver->remainingAttempts('key', 5));

        $this->driver->attempt('key', 5, 60);
        $this->assertSame(3, $this->driver->remainingAttempts('key', 5));
    }

    public function test_remaining_attempts_is_zero_when_throttled(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->driver->attempt('key', 3, 60);
        }

        $this->assertSame(0, $this->driver->remainingAttempts('key', 3));
    }

    // ── resetAttempts ─────────────────────────────────────────────────────────

    public function test_reset_clears_counter(): void
    {
        $this->driver->attempt('key', 3, 60);
        $this->driver->resetAttempts('key');

        $this->assertSame(3, $this->driver->remainingAttempts('key', 3));
    }

    public function test_reset_on_unknown_key_is_noop(): void
    {
        $this->driver->resetAttempts('never-used');

        $this->assertSame(3, $this->driver->remainingAttempts('never-used', 3));
    }

    // ── availableIn ───────────────────────────────────────────────────────────

    public function test_available_in_returns_zero_for_unknown_key(): void
    {
        $this->assertSame(0, $this->driver->availableIn('unknown'));
    }

    public function test_available_in_returns_positive_seconds_after_first_hit(): void
    {
        $this->driver->attempt('key', 3, 60);

        $this->assertGreaterThan(0, $this->driver->availableIn('key'));
        $this->assertLessThanOrEqual(60, $this->driver->availableIn('key'));
    }

    public function test_available_in_returns_zero_after_reset(): void
    {
        $this->driver->attempt('key', 3, 60);
        $this->driver->resetAttempts('key');

        $this->assertSame(0, $this->driver->availableIn('key'));
    }

    // ── isolation ─────────────────────────────────────────────────────────────

    public function test_different_keys_are_independent(): void
    {
        $this->driver->attempt('a', 2, 60);
        $this->driver->attempt('a', 2, 60);

        $this->assertTrue($this->driver->tooManyAttempts('a', 2));
        $this->assertFalse($this->driver->tooManyAttempts('b', 2));
    }

    public function test_keys_with_filesystem_unsafe_characters_are_isolated(): void
    {
        $this->driver->attempt('throttle:1.2.3.4/../x', 1, 60);

        $this->assertTrue($this->driver->tooManyAttempts('throttle:1.2.3.4/../x', 1));
        $this->assertFalse($this->driver->tooManyAttempts('throttle:1.2.3.4/../y', 1));
    }

    // ── file-specific ─────────────────────────────────────────────────────────

    public function test_directory_is_created_on_construction(): void
    {
        $this->assertDirectoryExists($this->dir);
    }

    /**
     * The point of a file driver: state outlives the object, unlike ArrayDriver.
     */
    public function test_state_persists_across_driver_instances(): void
    {
        $this->driver->attempt('key', 2, 60);

        $second = new FileDriver($this->dir);
        $second->attempt('key', 2, 60);

        $this->assertTrue($second->tooManyAttempts('key', 2));
        $this->assertTrue($this->driver->tooManyAttempts('key', 2));
    }

    public function test_expired_window_resets_the_counter(): void
    {
        // decay 0 → the window is already over on the next read.
        $this->driver->attempt('key', 1, 0);

        $this->assertFalse($this->driver->tooManyAttempts('key', 1));
        $this->assertTrue($this->driver->attempt('key', 1, 0));
    }

    /**
     * A live counter must survive the sweep — pruning it would hand the client a
     * fresh window and silently defeat the limit.
     */
    public function test_prune_removes_only_expired_counters(): void
    {
        $this->driver->attempt('live', 5, 600);
        $this->driver->attempt('stale', 5, 0); // decay 0 → already expired

        $this->assertCount(2, glob($this->dir . '/*.limit') ?: []);

        $this->assertSame(1, $this->driver->prune());

        $this->assertCount(1, glob($this->dir . '/*.limit') ?: []);
        $this->assertSame(4, $this->driver->remainingAttempts('live', 5));
    }

    public function test_prune_on_an_empty_directory_deletes_nothing(): void
    {
        $this->assertSame(0, $this->driver->prune());
    }

    /**
     * A truncated or hand-edited file has no readable window, so it can never be
     * reclaimed by expiry — the sweep is the only thing that removes it.
     */
    public function test_prune_removes_unreadable_counters(): void
    {
        file_put_contents($this->dir . '/' . sha1('broken') . '.limit', 'not json');

        $this->assertSame(1, $this->driver->prune());
        $this->assertSame([], glob($this->dir . '/*.limit') ?: []);
    }
}
