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
 * By default the key is the client IP from `RequestInterface::ip()`: the
 * connecting `REMOTE_ADDR`, unless it is one of `$trustedProxies`, in which
 * case the first untrusted `X-Forwarded-For` hop (walking from the right) is
 * used. Without trusted proxies the header is ignored — otherwise any client
 * could bypass the limit with a random header, or exhaust a victim's bucket by
 * forging the victim's IP. Pass a `$keyResolver` to throttle by something else
 * (e.g. authenticated user id).
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
     * @param list<string>                $trustedProxies IP addresses of reverse proxies whose
     *                                                   `X-Forwarded-For` header is honoured. Empty (default):
     *                                                   the header is ignored and `REMOTE_ADDR` is the key.
     */
    public function __construct(
        private RateLimiterInterface $limiter,
        private int $maxAttempts = 60,
        private int $decaySeconds = 60,
        private string $keyPrefix = 'throttle',
        private ?\Closure $keyResolver = null,
        private array $trustedProxies = [],
    ) {
    }

    /**
     * @param RequestInterface $request
     * @param callable         $next
     *
     * @return ResponseInterface
     *
     * @phpstan-impure
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
     * Client IP used as the default key; `unknown` when the server bag has none.
     *
     * @param RequestInterface $request
     *
     * @return string
     */
    private function resolveIp(RequestInterface $request): string
    {
        $ip = $request->ip($this->trustedProxies);

        return $ip !== '' ? $ip : 'unknown';
    }
}
