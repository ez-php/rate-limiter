<?php

declare(strict_types=1);

namespace Tests\Middleware;

use EzPhp\Http\Request;
use EzPhp\Http\Response;
use EzPhp\RateLimiter\ArrayDriver;
use EzPhp\RateLimiter\Middleware\ThrottleMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class ThrottleMiddlewareTest
 *
 * @package Tests\Middleware
 */
#[CoversClass(ThrottleMiddleware::class)]
#[UsesClass(ArrayDriver::class)]
final class ThrottleMiddlewareTest extends TestCase
{
    private ArrayDriver $limiter;

    protected function setUp(): void
    {
        $this->limiter = new ArrayDriver();
    }

    // ── pass-through ──────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_passes_request_when_under_limit(): void
    {
        $middleware = new ThrottleMiddleware($this->limiter, 3, 60);
        $request = $this->makeRequest();
        $next = fn (Request $r): Response => new Response('OK', 200);

        $response = $middleware->handle($request, $next);

        $this->assertSame(200, $response->status());
    }

    /**
     * @return void
     */
    public function test_adds_rate_limit_headers_on_pass(): void
    {
        $middleware = new ThrottleMiddleware($this->limiter, 5, 60);
        $request = $this->makeRequest();
        $next = fn (Request $r): Response => new Response('OK', 200);

        $response = $middleware->handle($request, $next);

        $this->assertSame('5', $response->headers()['X-RateLimit-Limit']);
        $this->assertSame('4', $response->headers()['X-RateLimit-Remaining']);
    }

    /**
     * @return void
     */
    public function test_remaining_header_decrements_on_each_request(): void
    {
        $middleware = new ThrottleMiddleware($this->limiter, 3, 60);
        $request = $this->makeRequest();
        $next = fn (Request $r): Response => new Response('OK', 200);

        $middleware->handle($request, $next);
        $response = $middleware->handle($request, $next);

        $this->assertSame('1', $response->headers()['X-RateLimit-Remaining']);
    }

    // ── throttle ──────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_returns_429_when_limit_exceeded(): void
    {
        $middleware = new ThrottleMiddleware($this->limiter, 1, 60);
        $request = $this->makeRequest();
        $next = fn (Request $r): Response => new Response('OK', 200);

        $middleware->handle($request, $next);
        $response = $middleware->handle($request, $next);

        $this->assertSame(429, $response->status());
        $this->assertSame('Too Many Requests', $response->body());
    }

    /**
     * @return void
     */
    public function test_next_is_not_called_when_throttled(): void
    {
        $middleware = new ThrottleMiddleware($this->limiter, 1, 60);
        $request = $this->makeRequest();
        $called = 0;
        $next = function (Request $r) use (&$called): Response {
            $called++;
            return new Response('OK', 200);
        };

        $middleware->handle($request, $next);
        $middleware->handle($request, $next);

        $this->assertSame(1, $called);
    }

    // ── IP resolution ─────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_uses_remote_addr_as_key(): void
    {
        $middleware = new ThrottleMiddleware($this->limiter, 1, 60);
        $requestA = $this->makeRequest(server: ['REMOTE_ADDR' => '1.2.3.4']);
        $requestB = $this->makeRequest(server: ['REMOTE_ADDR' => '9.9.9.9']);
        $next = fn (Request $r): Response => new Response('OK', 200);

        // exhaust key for 1.2.3.4
        $middleware->handle($requestA, $next);

        // 1.2.3.4 is throttled
        $this->assertSame(429, $middleware->handle($requestA, $next)->status());
        // 9.9.9.9 still passes
        $this->assertSame(200, $middleware->handle($requestB, $next)->status());
    }

    /**
     * @return void
     */
    public function test_prefers_x_forwarded_for_over_remote_addr(): void
    {
        $middleware = new ThrottleMiddleware($this->limiter, 1, 60);
        $request = $this->makeRequest(
            headers: ['x-forwarded-for' => '5.6.7.8, 10.0.0.1'],
            server: ['REMOTE_ADDR' => '10.0.0.1'],
        );
        $next = fn (Request $r): Response => new Response('OK', 200);

        // first request uses 5.6.7.8 as key → allowed
        $middleware->handle($request, $next);

        // second request with same forwarded IP → throttled
        $this->assertSame(429, $middleware->handle($request, $next)->status());

        // request with REMOTE_ADDR only → different key → still passes
        $plain = $this->makeRequest(server: ['REMOTE_ADDR' => '10.0.0.1']);
        $this->assertSame(200, $middleware->handle($plain, $next)->status());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $server
     *
     * @return Request
     */
    private function makeRequest(array $headers = [], array $server = []): Request
    {
        return new Request(
            method: 'GET',
            uri: '/',
            headers: $headers,
            server: array_merge(['REMOTE_ADDR' => '127.0.0.1'], $server),
        );
    }
}
