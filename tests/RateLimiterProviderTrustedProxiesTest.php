<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Application\Application;
use EzPhp\Http\Request;
use EzPhp\Http\Response;
use EzPhp\RateLimiter\ArrayDriver;
use EzPhp\RateLimiter\Middleware\ThrottleMiddleware;
use EzPhp\RateLimiter\RateLimiter;
use EzPhp\RateLimiter\RateLimiterServiceProvider;
use EzPhp\Testing\ApplicationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * `rate_limiter.trusted_proxies` reaches the container-built ThrottleMiddleware,
 * so X-Forwarded-For is honoured for requests coming through those proxies.
 *
 * @package Tests
 */
#[CoversClass(RateLimiterServiceProvider::class)]
#[UsesClass(RateLimiter::class)]
#[UsesClass(ArrayDriver::class)]
#[UsesClass(ThrottleMiddleware::class)]
final class RateLimiterProviderTrustedProxiesTest extends ApplicationTestCase
{
    protected function getBasePath(): string
    {
        $path = parent::getBasePath();
        file_put_contents(
            $path . '/config/rate_limiter.php',
            // Comma-separated string, as it comes from a TRUSTED_PROXIES env var.
            "<?php return ['driver' => 'array', 'trusted_proxies' => '10.0.0.1, 10.0.0.2'];",
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

    public function test_configured_proxies_are_trusted_by_the_middleware(): void
    {
        $middleware = $this->app()->make(ThrottleMiddleware::class);
        $next = fn (Request $r): Response => new Response('OK');

        $first = $middleware->handle($this->viaProxy('10.0.0.2', '1.2.3.4'), $next, '1', '60');
        $second = $middleware->handle($this->viaProxy('10.0.0.2', '1.2.3.4'), $next, '1', '60');
        $otherClient = $middleware->handle($this->viaProxy('10.0.0.1', '5.6.7.8'), $next, '1', '60');

        self::assertSame(200, $first->status());
        self::assertSame(429, $second->status());
        // Keyed by the forwarded client, not by the shared proxy address.
        self::assertSame(200, $otherClient->status());
    }

    /**
     * @param string $proxy
     * @param string $client
     *
     * @return Request
     */
    private function viaProxy(string $proxy, string $client): Request
    {
        return new Request(
            method: 'GET',
            uri: '/',
            headers: ['X-Forwarded-For' => $client],
            server: ['REMOTE_ADDR' => $proxy],
        );
    }
}
