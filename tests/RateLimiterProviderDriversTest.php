<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Application\Application;
use EzPhp\Cache\ArrayDriver as CacheArrayDriver;
use EzPhp\Cache\CacheInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\RateLimiter\CacheDriver;
use EzPhp\RateLimiter\RateLimiter;
use EzPhp\RateLimiter\RateLimiterInterface;
use EzPhp\RateLimiter\RateLimiterServiceProvider;
use EzPhp\RateLimiter\RedisDriver;
use EzPhp\Testing\ApplicationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Redis;
use RedisException;

/**
 * The `cache` and `redis` branches of RateLimiterServiceProvider's driver match.
 *
 * Each test writes its own config/rate_limiter.php (chosen by test name in
 * getBasePath(), which runs before bootstrap). The redis test is skipped when
 * ext-redis is missing or no server answers at REDIS_HOST/REDIS_PORT.
 *
 * @package Tests
 */
#[CoversClass(RateLimiterServiceProvider::class)]
#[UsesClass(RateLimiter::class)]
#[UsesClass(CacheDriver::class)]
#[UsesClass(RedisDriver::class)]
final class RateLimiterProviderDriversTest extends ApplicationTestCase
{
    /**
     * The provider builds the Redis driver while booting, so an unreachable server has to
     * skip the test before parent::setUp() bootstraps the application — inside the test
     * body it would already be an error.
     */
    protected function setUp(): void
    {
        if ($this->name() === 'test_redis_driver_is_built_from_config' && !self::redisAvailable()) {
            self::markTestSkipped('ext-redis is missing or no Redis answers at REDIS_HOST/REDIS_PORT.');
        }

        parent::setUp();
    }

    private static function redisAvailable(): bool
    {
        if (!extension_loaded('redis')) {
            return false;
        }

        try {
            return @(new Redis())->connect(self::redisHost(), self::redisPort(), 1.0);
        } catch (RedisException) {
            return false;
        }
    }

    protected function getBasePath(): string
    {
        $path = parent::getBasePath();
        $config = $this->name() === 'test_redis_driver_is_built_from_config'
            ? sprintf(
                "<?php return ['driver' => 'redis', 'redis' => ['host' => %s, 'port' => %d, 'database' => 1]];",
                var_export(self::redisHost(), true),
                self::redisPort(),
            )
            : "<?php return ['driver' => 'cache'];";
        file_put_contents($path . '/config/rate_limiter.php', $config);

        return $path;
    }

    protected function configureApplication(Application $app): void
    {
        $app->register(RateLimiterDriversCacheProvider::class);
        $app->register(RateLimiterServiceProvider::class);
    }

    protected function tearDown(): void
    {
        RateLimiter::resetInstance();
        parent::tearDown();
    }

    public function test_cache_driver_uses_the_bound_cache(): void
    {
        $driver = $this->app()->make(RateLimiterInterface::class);

        self::assertInstanceOf(CacheDriver::class, $driver);
        self::assertTrue($driver->attempt('provider-cache', 1, 60));
        self::assertFalse($driver->attempt('provider-cache', 1, 60));
    }

    public function test_redis_driver_is_built_from_config(): void
    {
        $probe = new Redis();
        $probe->connect(self::redisHost(), self::redisPort(), 1.0);
        $probe->select(1);
        $probe->del('provider-redis');

        $driver = $this->app()->make(RateLimiterInterface::class);

        self::assertInstanceOf(RedisDriver::class, $driver);
        self::assertTrue($driver->attempt('provider-redis', 1, 60));
        self::assertFalse($driver->attempt('provider-redis', 1, 60));

        $probe->del('provider-redis');
    }

    private static function redisHost(): string
    {
        return (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
    }

    private static function redisPort(): int
    {
        return (int) (getenv('REDIS_PORT') ?: 6379);
    }
}

/**
 * Binds the CacheInterface the `cache` driver resolves.
 */
final class RateLimiterDriversCacheProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CacheInterface::class, fn (): CacheInterface => new CacheArrayDriver());
    }
}
