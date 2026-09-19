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
singleton set during `RateLimiterServiceProvider::boot()`. Without a service provider
it falls back to an in-memory `ArrayDriver`, so it's safe to call in code paths that
run before the provider boots (e.g. early tests).

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
    public function availableIn(string $key): int; // seconds until the window resets; 0 if expired/absent
}
```

---

## License

MIT
