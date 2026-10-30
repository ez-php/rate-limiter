<?php

declare(strict_types=1);

namespace EzPhp\RateLimiter\Middleware;

use EzPhp\Http\RequestInterface;
use EzPhp\Http\ResponseInterface;

/**
 * Builds the response `ThrottleMiddleware` returns when a request is throttled.
 *
 * Bind an implementation in the container to answer in the application's own
 * error shape; `RateLimiterServiceProvider` hands it to the middleware. The
 * middleware still adds `Retry-After` to whatever the factory returns, and the
 * status should stay 429.
 *
 * @package EzPhp\RateLimiter\Middleware
 */
interface ThrottledResponseFactoryInterface
{
    /**
     * @param RequestInterface $request    The throttled request.
     * @param int              $retryAfter Seconds until the limit allows another attempt.
     *
     * @return ResponseInterface
     */
    public function create(RequestInterface $request, int $retryAfter): ResponseInterface;
}
