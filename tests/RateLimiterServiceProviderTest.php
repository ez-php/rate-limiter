<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Application\Application;
use EzPhp\RateLimiter\ArrayDriver;
use EzPhp\RateLimiter\RateLimiter;
use EzPhp\RateLimiter\RateLimiterInterface;
use EzPhp\RateLimiter\RateLimiterServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Smoke test: RateLimiterServiceProvider registers and boots its bindings in a
 * minimal application context without error.
 *
 * @package Tests
 */
#[CoversClass(RateLimiterServiceProvider::class)]
#[UsesClass(RateLimiter::class)]
#[UsesClass(ArrayDriver::class)]
final class RateLimiterServiceProviderTest extends TestCase
{
    /**
     * @param Application $app
     *
     * @return void
     */
    protected function configureApplication(Application $app): void
    {
        $app->register(RateLimiterServiceProvider::class);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        RateLimiter::resetInstance();
        parent::tearDown();
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function test_driver_is_bound_and_defaults_to_array_driver(): void
    {
        $driver = $this->app()->make(RateLimiterInterface::class);

        $this->assertInstanceOf(RateLimiterInterface::class, $driver);
        $this->assertInstanceOf(ArrayDriver::class, $driver);
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function test_driver_binding_is_a_singleton(): void
    {
        $this->assertSame(
            $this->app()->make(RateLimiterInterface::class),
            $this->app()->make(RateLimiterInterface::class),
        );
    }
}
