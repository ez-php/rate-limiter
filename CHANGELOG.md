# Changelog

All notable changes to `ez-php/rate-limiter` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [v1.2.0] — 2026-03-28

### Changed
- `ThrottleMiddleware::handle()` — first parameter type changed from `Request` to `RequestInterface`, in line with the updated `MiddlewareInterface` contract
- Updated `ez-php/contracts` dependency constraint to `^1.2`

---

## [v1.0.1] — 2026-03-25

### Changed
- Tightened all `ez-php/*` dependency constraints from `"*"` to `"^1.0"` for predictable resolution

---

## [v1.0.0] — 2026-03-24

### Added
- `RateLimiterInterface` — driver contract with `hit()`, `remaining()`, `resetAt()`, and `clear()` methods
- `ArrayDriver` — in-memory rate limiter; state resets per request; useful for testing
- `RedisDriver` — Redis-backed rate limiter using atomic `INCR` + `EXPIRE`; accurate under high concurrency
- `CacheDelegateDriver` — delegates to any `CacheInterface` implementation for cache-backed rate limiting
- `ThrottleMiddleware` — HTTP middleware that returns `429 Too Many Requests` when the limit is exceeded; sets `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `Retry-After` headers
- `RateLimiterServiceProvider` — resolves the configured driver, binds `RateLimiterInterface`, and registers `ThrottleMiddleware`
- `RateLimiterException` for driver initialization failures
