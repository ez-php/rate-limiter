<?php

declare(strict_types=1);

namespace EzPhp\RateLimiter;

use EzPhp\Cache\CacheInterface;
use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ServiceProvider;
use Redis;

/**
 * Class RateLimiterServiceProvider
 *
 * Reads `config/rate_limiter.php` and binds `RateLimiterInterface` to the
 * driver selected by the `rate_limiter.driver` config key.
 *
 * Supported drivers: `array` (default), `redis`, `cache`.
 *
 * @package EzPhp\RateLimiter
 */
final class RateLimiterServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function boot(): void
    {
        RateLimiter::setInstance(new RateLimiter($this->app->make(RateLimiterInterface::class)));
    }

    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(RateLimiterInterface::class, function (): RateLimiterInterface {
            $config = $this->app->make(ConfigInterface::class);

            /** @var string $driver */
            $driver = $config->get('rate_limiter.driver', 'array');

            return match ($driver) {
                'redis' => $this->makeRedisDriver($config),
                'cache' => $this->makeCacheDriver(),
                default => new ArrayDriver(),
            };
        });
    }

    /**
     * @param ConfigInterface $config
     *
     * @return RedisDriver
     */
    private function makeRedisDriver(ConfigInterface $config): RedisDriver
    {
        /** @var string $host */
        $host = $config->get('rate_limiter.redis.host', '127.0.0.1');
        /** @var int $port */
        $port = $config->get('rate_limiter.redis.port', 6379);
        /** @var int $database */
        $database = $config->get('rate_limiter.redis.database', 0);

        $redis = new Redis();
        $redis->connect($host, $port);

        if ($database !== 0) {
            $redis->select($database);
        }

        return new RedisDriver($redis);
    }

    /**
     * @return CacheDriver
     */
    private function makeCacheDriver(): CacheDriver
    {
        /** @var CacheInterface $cache */
        $cache = $this->app->make(CacheInterface::class);

        return new CacheDriver($cache);
    }
}
