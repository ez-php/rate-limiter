<?php

declare(strict_types=1);

namespace EzPhp\RateLimiter\Middleware;

use EzPhp\Contracts\MiddlewareInterface;
use EzPhp\Http\RequestInterface;
use EzPhp\Http\Response;
use EzPhp\RateLimiter\RateLimiterInterface;

/**
 * Class ThrottleMiddleware
 *
 * HTTP middleware that enforces a per-IP request rate limit.
 * The key is derived from the client IP: `X-Forwarded-For` (first entry) is
 * preferred; falls back to `REMOTE_ADDR` from the server bag.
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
     * @param RateLimiterInterface $limiter
     * @param int                  $maxAttempts  Requests allowed per window (default 60).
     * @param int                  $decaySeconds Window length in seconds (default 60).
     * @param string               $keyPrefix    Prefix for the rate limit key (default 'throttle').
     *                                           Use e.g. 'rate_limit:login' for auth endpoints.
     */
    public function __construct(
        private RateLimiterInterface $limiter,
        private int $maxAttempts = 60,
        private int $decaySeconds = 60,
        private string $keyPrefix = 'throttle',
    ) {
    }

    /**
     * @param RequestInterface $request
     * @param callable         $next
     *
     * @return Response
     */
    public function handle(RequestInterface $request, callable $next): Response
    {
        $key = $this->keyPrefix . ':' . $this->resolveIp($request);

        if (!$this->limiter->attempt($key, $this->maxAttempts, $this->decaySeconds)) {
            return (new Response('Too Many Requests', 429))
                ->withHeader('Retry-After', (string) $this->limiter->availableIn($key));
        }

        /** @var Response $response */
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
