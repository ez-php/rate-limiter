<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Application\Application;
use EzPhp\RateLimiter\FileDriver;
use EzPhp\RateLimiter\RateLimiter;
use EzPhp\RateLimiter\RateLimiterInterface;
use EzPhp\RateLimiter\RateLimiterServiceProvider;
use EzPhp\Testing\ApplicationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Wrong-typed config values fall back to the provider's defaults instead of
 * raising a TypeError (the provider used to trust them via @var casts).
 *
 * @package Tests
 */
#[CoversClass(RateLimiterServiceProvider::class)]
#[UsesClass(RateLimiter::class)]
#[UsesClass(FileDriver::class)]
final class RateLimiterProviderConfigTypesTest extends ApplicationTestCase
{
    protected function getBasePath(): string
    {
        $path = parent::getBasePath();
        file_put_contents(
            $path . '/config/rate_limiter.php',
            "<?php return ['driver' => 'file', 'file' => ['path' => 123]];",
        );

        return $path;
    }

    protected function configureApplication(Application $app): void
    {
        $app->register(RateLimiterServiceProvider::class);
    }

    protected function tearDown(): void
    {
        RateLimiter::resetInstance();
        parent::tearDown();
    }

    public function test_non_string_file_path_falls_back_to_the_default_directory(): void
    {
        $driver = $this->app()->make(RateLimiterInterface::class);

        self::assertInstanceOf(FileDriver::class, $driver);
        self::assertDirectoryExists(sys_get_temp_dir() . '/ez-php-rate-limiter');
    }
}
