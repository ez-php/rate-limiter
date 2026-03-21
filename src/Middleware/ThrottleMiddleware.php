<?php

declare(strict_types=1);

namespace EzPhp\RateLimiter\Middleware;

use EzPhp\Contracts\MiddlewareInterface;
use EzPhp\Http\Request;
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
     */
    public function __construct(
        private RateLimiterInterface $limiter,
        private int $maxAttempts = 60,
        private int $decaySeconds = 60,
    ) {
    }

    /**
     * @param Request  $request
     * @param callable $next
     *
     * @return Response
     */
    public function handle(Request $request, callable $next): Response
    {
        $key = 'throttle:' . $this->resolveIp($request);

        if (!$this->limiter->attempt($key, $this->maxAttempts, $this->decaySeconds)) {
            return new Response('Too Many Requests', 429);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) $this->limiter->remainingAttempts($key, $this->maxAttempts));
    }

    /**
     * @param Request $request
     *
     * @return string
     */
    private function resolveIp(Request $request): string
    {
        $forwarded = $request->header('x-forwarded-for');

        if (is_string($forwarded) && $forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        $remote = $request->server('REMOTE_ADDR');

        return is_string($remote) ? $remote : 'unknown';
    }
}
