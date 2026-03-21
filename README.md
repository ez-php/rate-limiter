# ez-php/rate-limiter

Request throttling for ez-php applications — three backends, a unified interface, and a plug-in `ThrottleMiddleware`.

---

## Installation

```bash
composer require ez-php/rate-limiter
```

---

## Drivers

| Driver | Persistence | External requirement |
|---|---|---|
| `ArrayDriver` | In-process (lost on restart) | None |
| `RedisDriver` | Redis | `ext-redis` |
| `CacheDriver` | Delegates to `ez-php/cache` | Any configured cache driver |

---

## Basic usage

```php
use EzPhp\RateLimiter\ArrayDriver;

$limiter = new ArrayDriver();

if (!$limiter->attempt('login:' . $ip, maxAttempts: 5, decaySeconds: 60)) {
    // Too many attempts — respond with 429
}

$limiter->remainingAttempts('login:' . $ip, 5); // how many hits are still allowed
$limiter->resetAttempts('login:' . $ip);        // clear the counter (e.g. on success)
```

---

## ThrottleMiddleware

Plug into the framework middleware pipeline for per-IP global or per-route throttling:

```php
// Global — in AppServiceProvider::boot()
$app->middleware(new ThrottleMiddleware($limiter, maxAttempts: 60, decaySeconds: 60));

// Per-route
$router->get('/login', [LoginController::class, 'store'])
    ->middleware(new ThrottleMiddleware($limiter, maxAttempts: 5, decaySeconds: 60));
```

The middleware:
- Resolves the client IP from `X-Forwarded-For` (first value) or falls back to `REMOTE_ADDR`.
- Returns **HTTP 429** with body `Too Many Requests` when the limit is exceeded.
- Adds `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers on every passing response.

---

## Service provider

Register `RateLimiterServiceProvider` in `provider/modules.php`:

```php
\EzPhp\RateLimiter\RateLimiterServiceProvider::class,
```

Create `config/rate_limiter.php`:

```php
<?php
return [
    'driver' => env('RATE_LIMITER_DRIVER', 'array'), // array | redis | cache

    'redis' => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'port'     => (int) env('REDIS_PORT', 6379),
        'database' => (int) env('REDIS_RATE_LIMITER_DB', 0),
    ],
];
```

---

## Interface

```php
interface RateLimiterInterface
{
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool;
    public function tooManyAttempts(string $key, int $maxAttempts): bool;
    public function remainingAttempts(string $key, int $maxAttempts): int;
    public function resetAttempts(string $key): void;
}
```

---

## License

MIT
