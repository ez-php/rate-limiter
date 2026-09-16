<?php

declare(strict_types=1);

namespace EzPhp\RateLimiter\Middleware;

use EzPhp\Contracts\MiddlewareInterface;
use EzPhp\Http\RequestInterface;
use EzPhp\Http\Response;
use EzPhp\Http\ResponseInterface;
use EzPhp\RateLimiter\RateLimiterInterface;

/**
 * Class ThrottleMiddleware
 *
 * HTTP middleware that enforces a per-key request rate limit.
 * By default the key is derived from the client IP: `X-Forwarded-For` (first
 * entry) is preferred; falls back to `REMOTE_ADDR` from the server bag. Pass
 * a `$keyResolver` to throttle by something else (e.g. authenticated user id).
 *
 * On throttle: returns HTTP 429 with a plain-text body.
 * On pass:     adds `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers.
 *
 * @package EzPhp\RateLimiter\Middleware
 */
final readonly class ThrottleMiddleware implements MiddlewareInterface
{
    /**
     * ThrottleMiddleware Constructor
     *
     * @param RateLimiterInterface        $limiter
     * @param int                         $maxAttempts  Requests allowed per window (default 60).
     * @param int                         $decaySeconds Window length in seconds (default 60).
     * @param string                      $keyPrefix    Prefix for the rate limit key (default 'throttle').
     *                                                   Use e.g. 'rate_limit:login' for auth endpoints.
     * @param (\Closure(RequestInterface): string)|null $keyResolver Overrides the default IP-based key
     *                                                   derivation; receives the request and returns the
     *                                                   part of the key appended after `$keyPrefix`.
     */
    public function __construct(
        private RateLimiterInterface $limiter,
        private int $maxAttempts = 60,
        private int $decaySeconds = 60,
        private string $keyPrefix = 'throttle',
        private ?\Closure $keyResolver = null,
    ) {
    }

    /**
     * @param RequestInterface $request
     * @param callable         $next
     *
     * @return ResponseInterface
     */
    public function handle(RequestInterface $request, callable $next): ResponseInterface
    {
        $keySuffix = $this->keyResolver !== null
            ? ($this->keyResolver)($request)
            : $this->resolveIp($request);

        $key = $this->keyPrefix . ':' . $keySuffix;

        if (!$this->limiter->attempt($key, $this->maxAttempts, $this->decaySeconds)) {
            return (new Response('Too Many Requests', 429))
                ->withHeader('Retry-After', (string) $this->limiter->availableIn($key));
        }

        /** @var ResponseInterface $response */
        $response = $next($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) $this->limiter->remainingAttempts($key, $this->maxAttempts));
    }

    /**
     * @param RequestInterface $request
     *
     * @return string
     */
    private function resolveIp(RequestInterface $request): string
    {
        $forwarded = $request->header('x-forwarded-for');

        if (is_string($forwarded) && $forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        $remote = $request->server('REMOTE_ADDR');

        return is_string($remote) ? $remote : 'unknown';
    }
}
