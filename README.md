# ez-php/rate-limiter

Request throttling for ez-php applications — three backends, a unified interface, and a plug-in `ThrottleMiddleware`.

---

## Installation

```bash
composer require ez-php/rate-limiter
```

---

## Drivers

| Driver | Persistence | External requirement | Concurrency-safe |
|---|---|---|---|
| `ArrayDriver` | In-process (lost on restart) | None | **No** — single-process/test use only |
| `FileDriver` | Files on disk | None | Yes — `flock(LOCK_EX)`, single host |
| `RedisDriver` | Redis | `ext-redis` | Yes — atomic `INCR`, fixed window |
| `SlidingWindowRedisDriver` | Redis | `ext-redis` | Yes — sorted set, true sliding window |
| `CacheDriver` | Delegates to `ez-php/cache` | Any configured cache driver | Driver-dependent |

> **Warning:** `ArrayDriver` uses a plain PHP array without atomic operations. Concurrent requests (e.g. PHP-FPM workers) can race and both be allowed through simultaneously. Use `FileDriver`, `RedisDriver` or `CacheDriver` in production.

### FileDriver

For single-host deployments that have no Redis. Counters persist across requests and
process restarts, and the whole read-modify-write in `attempt()` runs under an
exclusive `flock()`, so concurrent PHP-FPM workers cannot both slip past the limit.

```php
use EzPhp\RateLimiter\FileDriver;

$limiter = new FileDriver('/var/www/storage/rate-limiter');
$limiter->attempt('login:1.2.3.4', 5, 60);
```

Or via config:

```php
// config/rate_limiter.php
return [
    'driver' => 'file',
    'file'   => ['path' => __DIR__ . '/../storage/rate-limiter'],
];
```

- One file per key; the key is `sha1()`-hashed, so a key containing `/` or `..` is safe.
- **Single host only.** Locking is filesystem-level — a shared network mount across
  hosts is not supported. Use `RedisDriver` for multi-host deployments.
- Counter files are not swept automatically. A key is reclaimed when it is next read,
  but keys that stop being used (e.g. one per client IP) leave files behind. Call
  `prune()` from cron or a scheduled command if the endpoint is exposed to untrusted
  traffic:

  ```php
  $deleted = $limiter->prune(); // removes expired counters, returns how many
  ```

  Live counters are left untouched. `prune()` is only on `FileDriver`, not on
  `RateLimiterInterface` — the other drivers expire their own keys.

### SlidingWindowRedisDriver

`RedisDriver`'s fixed window resets in one block: a limit of 5/60s allows 5 requests at
`t=0.9s` and another 5 at `t=1.0s` (two different windows), i.e. 10 requests in ~0.1s at a
window boundary. `SlidingWindowRedisDriver` avoids that by tracking every hit's timestamp
in a Redis sorted set and pruning anything older than the trailing `decaySeconds` window on
every call — "no more than N requests in *any* trailing 60 seconds", not "N requests per
calendar-aligned 60-second bucket". The cost: one sorted-set member per hit instead of a
single counter, and every `attempt()` does a range-delete before the count check.

```php
use EzPhp\RateLimiter\SlidingWindowRedisDriver;
use Redis;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$limiter = new SlidingWindowRedisDriver($redis);
$limiter->attempt('login:1.2.3.4', 5, 60); // no more than 5 hits in any trailing 60s
```

Same `RateLimiterInterface` contract as every other driver — drop-in replacement for
`RedisDriver` wherever the fixed-window/sliding-window distinction matters.

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

## Using the facade

`RateLimiter` mirrors `RateLimiterInterface` as static methods, backed by a managed
singleton set during `RateLimiterServiceProvider::boot()`. Calling it before the
provider boots throws a `RuntimeException` — there is intentionally no in-memory
fallback, because a per-process `ArrayDriver` would silently let every PHP-FPM worker
count from zero. In tests, set one explicitly:
`RateLimiter::setInstance(new RateLimiter(new ArrayDriver()))`.

```php
use EzPhp\RateLimiter\RateLimiter;

if (!RateLimiter::attempt('login:' . $ip, maxAttempts: 5, decaySeconds: 60)) {
    $retryIn = RateLimiter::availableIn('login:' . $ip);
    // respond 429, e.g. with a Retry-After: $retryIn header
}

RateLimiter::tooManyAttempts('login:' . $ip, 5);
RateLimiter::remainingAttempts('login:' . $ip, 5);
RateLimiter::resetAttempts('login:' . $ip);
```

In tests, call `RateLimiter::resetInstance()` in `tearDown()` to clear the static
singleton between test cases.

---

## ThrottleMiddleware

Plug into the framework middleware pipeline for per-IP global or per-route throttling:

Middleware is registered by class name and resolved from the container. For one global
limit, bind the configured `ThrottleMiddleware` in a provider's `register()` and add the class:

```php
// AppServiceProvider::register()
$this->app->bind(ThrottleMiddleware::class, fn (): ThrottleMiddleware => new ThrottleMiddleware(
    $this->app->make(RateLimiterInterface::class),
    maxAttempts: 60,
    decaySeconds: 60,
    trustedProxies: ['10.0.0.1'], // behind a reverse proxy / load balancer: list its address(es)
));

// Global — before bootstrap (e.g. public/index.php)
$app->middleware(ThrottleMiddleware::class);
```

For per-route limits, pass them as middleware parameters — `maxAttempts,decaySeconds[,bucket]`
(`ThrottleMiddleware` implements `ParameterizedMiddlewareInterface`). The same container-built
instance serves every route; only the parameters differ:

```php
$app->middlewareAlias('throttle', ThrottleMiddleware::class); // before bootstrap

$router->post('/login', [LoginController::class, 'store'])->middleware('throttle:5,60');
$router->post('/register', [RegisterController::class, 'store'])->middleware('throttle:3,60');
$router->post('/password/reset', [ResetController::class, 'store'])->middleware('throttle:3,60,password-reset');
```

Each distinct `max,decay` pair gets its own counter, separate from the global limit's; routes that
should share one counter name the same third parameter (`bucket`). Malformed parameters (non-numeric,
zero, or more than three) throw `LogicException` — a configuration error, not a 429.

The middleware:
- Keys the limit on the client IP: `REMOTE_ADDR`, or — only when `REMOTE_ADDR` is one of the
  `trustedProxies` you pass — the first untrusted `X-Forwarded-For` hop (walking from the right).
  Without trusted proxies the header is ignored, so clients cannot dodge the limit with a forged header.
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
    'driver' => getenv('RATE_LIMITER_DRIVER') ?: 'array', // array | redis | cache

    'redis' => [
        'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port'     => (int) (getenv('REDIS_PORT') ?: 6379),
        'database' => (int) (getenv('REDIS_RATE_LIMITER_DB') ?: 0),
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
    public function availableIn(string $key): int; // seconds until the window resets; 0 if expired/absent
}
```

---

## License

MIT
