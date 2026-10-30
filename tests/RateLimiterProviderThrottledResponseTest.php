<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Application\Application;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\Http\Request;
use EzPhp\Http\RequestInterface;
use EzPhp\Http\Response;
use EzPhp\Http\ResponseInterface;
use EzPhp\RateLimiter\ArrayDriver;
use EzPhp\RateLimiter\Middleware\ThrottledResponseFactoryInterface;
use EzPhp\RateLimiter\Middleware\ThrottleMiddleware;
use EzPhp\RateLimiter\RateLimiter;
use EzPhp\RateLimiter\RateLimiterServiceProvider;
use EzPhp\Testing\ApplicationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * A bound ThrottledResponseFactoryInterface reaches the container-built
 * ThrottleMiddleware, so an application answers 429 in its own error shape.
 *
 * @package Tests
 */
#[CoversClass(RateLimiterServiceProvider::class)]
#[UsesClass(RateLimiter::class)]
#[UsesClass(ArrayDriver::class)]
#[UsesClass(ThrottleMiddleware::class)]
final class RateLimiterProviderThrottledResponseTest extends ApplicationTestCase
{
    protected function configureApplication(Application $app): void
    {
        $app->register(RateLimiterThrottledResponseProvider::class);
        $app->register(RateLimiterServiceProvider::class);
    }

    protected function tearDown(): void
    {
        RateLimiter::resetInstance();
        parent::tearDown();
    }

    public function test_the_bound_factory_shapes_the_429(): void
    {
        $middleware = $this->app()->make(ThrottleMiddleware::class);
        $next = static fn (): Response => new Response('OK');
        $request = new Request('GET', '/', server: ['REMOTE_ADDR' => '7.7.7.7']);

        $middleware->handle($request, $next, '1', '60');
        $response = $middleware->handle($request, $next, '1', '60');

        self::assertSame(429, $response->status());
        self::assertInstanceOf(Response::class, $response);
        self::assertSame('{"error":"too_many_requests"}', $response->body());
        self::assertArrayHasKey('Retry-After', $response->headers());
    }
}

/**
 * Binds a flat-JSON throttled response, as an application would.
 */
final class RateLimiterThrottledResponseProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            ThrottledResponseFactoryInterface::class,
            static fn (): ThrottledResponseFactoryInterface => new class () implements ThrottledResponseFactoryInterface {
                public function create(RequestInterface $request, int $retryAfter): ResponseInterface
                {
                    return new Response('{"error":"too_many_requests"}', 429);
                }
            },
        );
    }
}
