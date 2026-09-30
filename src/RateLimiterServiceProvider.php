<?php

declare(strict_types=1);

namespace EzPhp\RateLimiter;

use EzPhp\Cache\CacheInterface;
use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\RateLimiter\Middleware\ThrottleMiddleware;
use Redis;

/**
 * Class RateLimiterServiceProvider
 *
 * Reads `config/rate_limiter.php` and binds `RateLimiterInterface` to the
 * driver selected by the `rate_limiter.driver` config key.
 *
 * Supported drivers: `array` (default), `file`, `redis`, `cache`.
 *
 * Also binds `ThrottleMiddleware` with the default limits and the proxies from
 * `rate_limiter.trusted_proxies` (a list, or a comma-separated string such as a
 * `TRUSTED_PROXIES` env var). An application binding registered later replaces it.
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

            $driver = self::configString($config, 'rate_limiter.driver', 'array');

            return match ($driver) {
                'file' => $this->makeFileDriver($config),
                'redis' => $this->makeRedisDriver($config),
                'cache' => $this->makeCacheDriver(),
                default => new ArrayDriver(),
            };
        });

        $this->app->bind(ThrottleMiddleware::class, function (): ThrottleMiddleware {
            return new ThrottleMiddleware(
                $this->app->make(RateLimiterInterface::class),
                trustedProxies: self::configList($this->app->make(ConfigInterface::class), 'rate_limiter.trusted_proxies'),
            );
        });
    }

    /**
     * @param ConfigInterface $config
     *
     * @return FileDriver
     */
    private function makeFileDriver(ConfigInterface $config): FileDriver
    {
        $path = self::configString($config, 'rate_limiter.file.path', sys_get_temp_dir() . '/ez-php-rate-limiter');

        return new FileDriver($path);
    }

    /**
     * @param ConfigInterface $config
     *
     * @return RedisDriver
     */
    private function makeRedisDriver(ConfigInterface $config): RedisDriver
    {
        $host = self::configString($config, 'rate_limiter.redis.host', '127.0.0.1');
        $port = self::configInt($config, 'rate_limiter.redis.port', 6379);
        $database = self::configInt($config, 'rate_limiter.redis.database', 0);

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

    /**
     * Read a string config value, falling back to $default when it is missing or not a string.
     *
     * @param ConfigInterface $config
     * @param string          $key
     * @param string          $default
     *
     * @return string
     */
    private static function configString(ConfigInterface $config, string $key, string $default): string
    {
        $value = $config->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Read a list of strings from a list config value or a comma-separated string.
     * Blank entries and non-string list items are dropped.
     *
     * @param ConfigInterface $config
     * @param string          $key
     *
     * @return list<string>
     */
    private static function configList(ConfigInterface $config, string $key): array
    {
        $value = $config->get($key, []);
        $items = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);
        $list = [];

        foreach ($items as $item) {
            if (is_string($item) && trim($item) !== '') {
                $list[] = trim($item);
            }
        }

        return $list;
    }

    /**
     * Read an int config value (int or numeric string, e.g. an uncast getenv() result),
     * falling back to $default otherwise.
     *
     * @param ConfigInterface $config
     * @param string          $key
     * @param int             $default
     *
     * @return int
     */
    private static function configInt(ConfigInterface $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : $default;
    }
}
