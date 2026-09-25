<?php

declare(strict_types=1);

namespace EzPhp\RateLimiter\Middleware;

use EzPhp\Contracts\ParameterizedMiddlewareInterface;
use EzPhp\Http\RequestInterface;
use EzPhp\Http\Response;
use EzPhp\Http\ResponseInterface;
use EzPhp\RateLimiter\RateLimiterInterface;
use LogicException;

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
 * Per-route limits come from registration parameters —
 * `'throttle:maxAttempts,decaySeconds[,bucket]'` with an alias, or
 * `ThrottleMiddleware::class . ':5,60'` — so one container-built instance
 * serves every route. Each parameter set gets its own counter unless routes
 * name the same `bucket`.
 *
 * @package EzPhp\RateLimiter\Middleware
 */
final readonly class ThrottleMiddleware implements ParameterizedMiddlewareInterface
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
     * @param string           ...$parameters `maxAttempts`, `decaySeconds` and optional `bucket` from a
     *                                        `'throttle:5,60[,bucket]'` registration; none = constructor values.
     *
     * @return ResponseInterface
     *
     * @throws LogicException When the parameters are malformed.
     *
     * @phpstan-impure
     */
    public function handle(RequestInterface $request, callable $next, string ...$parameters): ResponseInterface
    {
        [$maxAttempts, $decaySeconds, $keyPrefix] = $this->limits(array_values($parameters));

        $keySuffix = $this->keyResolver !== null
            ? ($this->keyResolver)($request)
            : $this->resolveIp($request);

        $key = $keyPrefix . ':' . $keySuffix;

        if (!$this->limiter->attempt($key, $maxAttempts, $decaySeconds)) {
            return (new Response('Too Many Requests', 429))
                ->withHeader('Retry-After', (string) $this->limiter->availableIn($key));
        }

        /** @var ResponseInterface $response */
        $response = $next($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) $this->limiter->remainingAttempts($key, $maxAttempts));
    }

    /**
     * Resolve the limit, window and key prefix for this call.
     *
     * Without parameters the constructor values apply. With parameters, the key
     * prefix defaults to `<keyPrefix>:<max>,<decay>` so each distinct limit gets its
     * own counter (and never shares one with the global, unparameterized limit);
     * a third parameter names a bucket that several routes can share.
     *
     * @param list<string> $parameters
     *
     * @return array{0: int, 1: int, 2: string}
     *
     * @throws LogicException When the parameters are malformed.
     */
    private function limits(array $parameters): array
    {
        if ($parameters === []) {
            return [$this->maxAttempts, $this->decaySeconds, $this->keyPrefix];
        }

        if (count($parameters) < 2 || count($parameters) > 3) {
            throw new LogicException("ThrottleMiddleware expects 'throttle:maxAttempts,decaySeconds[,bucket]'.");
        }

        $maxAttempts = self::positiveInt($parameters[0], 'maxAttempts');
        $decaySeconds = self::positiveInt($parameters[1], 'decaySeconds');
        $bucket = $parameters[2] ?? ($maxAttempts . ',' . $decaySeconds);

        return [$maxAttempts, $decaySeconds, $this->keyPrefix . ':' . $bucket];
    }

    /**
     * @param string $value
     * @param string $name
     *
     * @return int
     *
     * @throws LogicException When $value is not a positive integer.
     */
    private static function positiveInt(string $value, string $name): int
    {
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new LogicException("ThrottleMiddleware parameter '{$name}' must be a positive integer, '{$value}' given.");
        }

        return (int) $value;
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
