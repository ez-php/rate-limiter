# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
2. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
3. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse   # PHPStan only
composer cs        # CS Fixer only
composer test      # PHPUnit only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

### 3 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "0.*"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | `REDIS_PORT` |
|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 |
| `ez-php/framework` | 3307 | — |
| `ez-php/orm` | 3309 | — |
| `ez-php/cache` | — | 6380 |
| `ez-php/queue` | 3310 | 6381 |
| `ez-php/rate-limiter` | — | 6382 |
| **next free** | **3311** | **6383** |

Only set a port for services the module actually uses. Modules without external services need no port config.

---

# Package: ez-php/rate-limiter

Request throttling for ez-php applications — three backend drivers, a unified interface, and `ThrottleMiddleware`.

---

## Source Structure

```
src/
├── RateLimiterInterface.php           — attempt/tooManyAttempts/remainingAttempts/resetAttempts contract
├── ArrayDriver.php                    — In-process PHP array; per-key decay window; no external deps
├── RedisDriver.php                    — Redis backend via ext-redis; INCR + EXPIRE per decay window
├── CacheDriver.php                    — Delegates to ez-php/cache CacheInterface; stores {hits, reset_at} array
├── RateLimiterServiceProvider.php     — Binds RateLimiterInterface per rate_limiter.driver config
└── Middleware/
    └── ThrottleMiddleware.php         — MiddlewareInterface; per-IP throttle; 429 on exceed; rate-limit headers

tests/
├── TestCase.php                       — Base PHPUnit test case
├── ArrayDriverTest.php                — Full contract tests; no external infrastructure
├── RedisDriverTest.php                — Full contract tests; requires live Redis; skipped without ext-redis
├── CacheDriverTest.php                — Full contract tests; uses ez-php/cache ArrayDriver as backing store
└── Middleware/
    └── ThrottleMiddlewareTest.php     — Pass-through, 429, headers, IP resolution via ArrayDriver
```

---

## Key Classes and Responsibilities

### RateLimiterInterface (`src/RateLimiterInterface.php`)

The single contract all drivers implement.

| Method | Signature | Behaviour |
|---|---|---|
| `attempt` | `attempt(string $key, int $maxAttempts, int $decaySeconds): bool` | Records a hit if under limit (returns true); refuses if at limit (returns false, no hit recorded) |
| `tooManyAttempts` | `tooManyAttempts(string $key, int $maxAttempts): bool` | Returns true if current hits >= maxAttempts |
| `remainingAttempts` | `remainingAttempts(string $key, int $maxAttempts): int` | Returns max(0, maxAttempts - hits) |
| `resetAttempts` | `resetAttempts(string $key): void` | Clears the counter; no-op on unknown keys |

**Decay window** — The window starts on the first hit and runs for `$decaySeconds`. Subsequent hits within the same window do not push the reset time forward.

---

### ArrayDriver (`src/ArrayDriver.php`)

In-process store. Each entry is `['hits' => int, 'reset_at' => int]`. Expiry is checked lazily on every read via `cleanIfExpired()`.

- No persistence — state is lost when the PHP process ends.
- Safe for use in tests without external infrastructure.
- `cleanIfExpired()` removes entries whose `reset_at` is in the past.

---

### RedisDriver (`src/RedisDriver.php`)

Redis store via `ext-redis`. Uses `INCR` for atomic counter increments and `EXPIRE` to set the window TTL on the first hit. Subsequent hits within the window only increment the counter — `EXPIRE` is not called again, so the window is not extended.

- First hit: `INCR key` (returns 1) → `EXPIRE key $decaySeconds`
- Subsequent hits within the window: `INCR key` only
- `resetAttempts()` calls `DEL key`
- Throws `RuntimeException` at construction if `ext-redis` is not loaded

---

### CacheDriver (`src/CacheDriver.php`)

Delegates to any `CacheInterface` (Array, File, Redis). Each entry is stored as `['hits' => int, 'reset_at' => int]`. The cache TTL is computed as remaining seconds to `reset_at`, so the entry expires together with the window.

- Window is fixed from the first hit; subsequent writes compute `max(1, reset_at - time())` as TTL.
- Does not require `ext-redis` — works with any configured cache driver.
- `readEntry()` defensively validates the stored value shape.

---

### ThrottleMiddleware (`src/Middleware/ThrottleMiddleware.php`)

Implements `MiddlewareInterface`. Resolves the client IP, calls `attempt()`, and either:
- Returns **HTTP 429** (`Too Many Requests`) immediately — `$next` is not called.
- Calls `$next($request)`, then adds `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers to the response.

**IP resolution order:**
1. `X-Forwarded-For` header — first comma-separated value, trimmed
2. `REMOTE_ADDR` server variable
3. Fallback: `'unknown'`

The throttle key is `'throttle:' . $ip`.

---

### RateLimiterServiceProvider (`src/RateLimiterServiceProvider.php`)

Reads `config/rate_limiter.php` and binds `RateLimiterInterface` lazily to the matching driver.

| Config key | Type | Default | Meaning |
|---|---|---|---|
| `rate_limiter.driver` | string | `'array'` | `'array'`, `'redis'`, or `'cache'` |
| `rate_limiter.redis.host` | string | `'127.0.0.1'` | Redis hostname |
| `rate_limiter.redis.port` | int | `6379` | Redis port |
| `rate_limiter.redis.database` | int | `0` | Redis database index |

Unknown driver values fall back to `ArrayDriver`. The `cache` driver resolves `CacheInterface` from the container — `CacheServiceProvider` must be registered first.

---

## Design Decisions and Constraints

- **`attempt()` does not count rejected hits** — A call that returns false (limit already reached) does not increment the counter. The counter only advances when a request is actually allowed through. This makes the hit count an accurate record of served requests, not attempted ones.
- **Fixed decay window from first hit** — The window starts on the first `attempt()` call and ends `$decaySeconds` later regardless of further activity. This is a fixed window, not a sliding window. Sliding windows require storing per-request timestamps and are more expensive. Fixed windows are simpler and sufficient for most throttle use cases.
- **`RedisDriver` uses INCR + conditional EXPIRE** — `INCR` is atomic in Redis. Setting `EXPIRE` only on the first hit (when the counter returns 1) avoids resetting the window on every request. If `INCR` returns `false` (should not happen in practice), the expiry is still set defensively.
- **`CacheDriver` computes remaining TTL** — On every write, the TTL is computed as `max(1, reset_at - time())`. This ensures the cache entry expires at the same moment as the rate limit window, without resetting the window on each hit.
- **`ThrottleMiddleware` does not call `$next` on throttle** — The 429 response is returned immediately, saving downstream middleware and controller execution. The response body is intentionally minimal (`Too Many Requests`); consumers requiring a JSON body should extend or wrap this middleware.
- **IP from `X-Forwarded-For` is not trusted blindly** — Only the first value is used (the client IP in standard proxy setups). This can be spoofed if the load balancer does not strip the header. Applications behind untrusted proxies should configure trusted proxy handling at the infrastructure level.
- **`ez-php/cache` is a hard `require`** — `CacheDriver` is a first-class backend, not an optional add-on. Requiring `ez-php/cache` ensures all three drivers are always available without conditional autoloading. The module is lightweight (no heavy deps).

---

## Testing Approach

- **`ArrayDriverTest`** — No external infrastructure. Tests cover the full interface: attempt (allow, allow-up-to-max, deny), tooManyAttempts, remainingAttempts, resetAttempts, key isolation.
- **`RedisDriverTest`** — Requires a live Redis instance (available via Docker). Uses Redis database `2` to avoid colliding with application data. Tests are automatically skipped when `ext-redis` is not loaded. `flushDB()` is called in `setUp` and `tearDown`.
- **`CacheDriverTest`** — Uses `ez-php/cache`'s `ArrayDriver` as the backing store — no external infrastructure needed. Covers the same contract surface as `ArrayDriverTest`.
- **`ThrottleMiddlewareTest`** — Uses `ArrayDriver` directly; no Docker required. Covers: pass-through, 429 on throttle, rate-limit headers, next-not-called-when-throttled, per-IP isolation, `X-Forwarded-For` preference over `REMOTE_ADDR`.
- **`#[UsesClass]` required** — `beStrictAboutCoverageMetadata=true` is set in `phpunit.xml`. Declare indirectly used classes with `#[UsesClass]`.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---|---|
| IP trust / proxy configuration | Application infrastructure (Nginx, load balancer) |
| Sliding window rate limiting | Application layer (requires per-request timestamp storage) |
| Login brute-force protection (specific logic) | Application layer, using this module's interface |
| API key quotas | Application layer (different key scheme + persistence) |
| Circuit breaker | Application layer or a dedicated module |
| Rate limit storage shared across services | Infrastructure layer (centralised Redis, API gateway) |
