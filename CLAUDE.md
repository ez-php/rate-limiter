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
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
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
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

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
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
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

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` unless `--namespace=` overrides it (`bignum` → `BigNum` and
`opcache` → `OPCache` are existing exceptions the guess gets wrong).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4`), `phpstan.neon`, `phpunit.xml`
(test suite **and** coverage source), and `packages.sh` (alphabetical position).

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — editing it marks all ~40
  `CLAUDE.md` copies as drifted at once, so the next `composer full` would fail for
  a brand-new module. The generator prints which ports to claim instead.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| **next free** | **3311** | **6383** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project is the one exception, since it has no host/container split and uses `REDIS_PORT` for both.

### 5 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

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
├── FileDriver.php                     — File-backed counters; flock(LOCK_EX) read-modify-write; single-host persistence
├── RateLimiter.php                    — Static facade backed by a managed singleton; falls back to ArrayDriver
├── RateLimiterServiceProvider.php     — Binds RateLimiterInterface per config; sets RateLimiter singleton in boot()
└── Middleware/
    └── ThrottleMiddleware.php         — MiddlewareInterface; per-IP throttle; 429 on exceed; rate-limit headers

tests/
├── TestCase.php                       — Base PHPUnit test case
├── ArrayDriverTest.php                — Full contract tests; no external infrastructure
├── RedisDriverTest.php                — Full contract tests; requires live Redis; skipped without ext-redis
├── CacheDriverTest.php                — Full contract tests; uses ez-php/cache ArrayDriver as backing store
├── RateLimiterFileDriverTest.php      — Full contract tests; temp directory; name-prefixed to avoid a class clash
├── RateLimiterTest.php                — Covers facade: instance management, all four static methods
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
| `rate_limiter.driver` | string | `'array'` | `'array'`, `'file'`, `'redis'`, or `'cache'` |
| `rate_limiter.file.path` | string | `sys_get_temp_dir().'/ez-php-rate-limiter'` | Counter directory (file driver only) |
| `rate_limiter.redis.host` | string | `'127.0.0.1'` | Redis hostname |
| `rate_limiter.redis.port` | int | `6379` | Redis port |
| `rate_limiter.redis.database` | int | `0` | Redis database index |

Unknown driver values fall back to `ArrayDriver`. The `cache` driver resolves `CacheInterface` from the container — `CacheServiceProvider` must be registered first.

---

## Design Decisions and Constraints

- **`attempt()` does not count rejected hits** — A call that returns false (limit already reached) does not increment the counter. The counter only advances when a request is actually allowed through. This makes the hit count an accurate record of served requests, not attempted ones.
- **Fixed decay window from first hit** — The window starts on the first `attempt()` call and ends `$decaySeconds` later regardless of further activity. This is a fixed window, not a sliding window. Sliding windows require storing per-request timestamps and are more expensive. Fixed windows are simpler and sufficient for most throttle use cases.
- **`RedisDriver` uses INCR + conditional EXPIRE** — `INCR` is atomic in Redis. Setting `EXPIRE` only on the first hit (when the counter returns 1) avoids resetting the window on every request. If `INCR` returns `false` (should not happen in practice), the expiry is still set defensively.
- **`FileDriver` holds an exclusive lock across the whole read-modify-write** — `attempt()` opens the counter file with `fopen('c+')`, takes `flock(LOCK_EX)`, then reads, decides and writes before releasing. This is what makes it safe under PHP-FPM: a non-locking implementation (like `ArrayDriver`, or a naive `file_put_contents`) lets two workers read the same count, both pass the check, and both write the same value — so the limit would never trip. Read-only methods take `LOCK_SH`.
- **`FileDriver` hashes the key into the filename** — Throttle keys embed the client IP and arbitrary caller-supplied text, which may contain `/` or `..`. `sha1($key)` gives a fixed, filesystem-safe name and makes traversal impossible by construction.
- **`FileDriver::prune()` is opt-in, not automatic** — An expired key is reclaimed when it is next read, but nothing reclaims keys that stop being used. Throttle keys are `throttle:<client-ip>`, so a public endpoint accumulates one `.limit` file per unique IP — driven by untrusted input, in exactly the single-host-without-Redis deployment this driver targets. `prune()` deletes every counter whose window has expired (and any file whose contents are unreadable, which expiry can never reclaim) and returns the count. It is not called from the hot path: that would cost a directory scan per request. Run it from `schedule:run` or cron. It is deliberately **not** on `RateLimiterInterface` — the other drivers have nothing to prune, since Redis and the cache expire their own keys and `ArrayDriver` dies with the process. Live counters are re-checked under `LOCK_EX` and left alone; deleting one would hand that client a fresh window.
- **`FileDriver` is single-host** — Locking is filesystem-level, so a shared network mount across hosts is not a supported configuration. Use `RedisDriver` for multi-host deployments.
- **`CacheDriver` computes remaining TTL** — On every write, the TTL is computed as `max(1, reset_at - time())`. This ensures the cache entry expires at the same moment as the rate limit window, without resetting the window on each hit.
- **`ThrottleMiddleware` does not call `$next` on throttle** — The 429 response is returned immediately, saving downstream middleware and controller execution. The response body is intentionally minimal (`Too Many Requests`); consumers requiring a JSON body should extend or wrap this middleware.
- **IP from `X-Forwarded-For` is not trusted blindly** — Only the first value is used (the client IP in standard proxy setups). This can be spoofed if the load balancer does not strip the header. Applications behind untrusted proxies should configure trusted proxy handling at the infrastructure level.
- **`ez-php/cache` is a hard `require`** — `CacheDriver` is a first-class backend, not an optional add-on. Requiring `ez-php/cache` ensures all three drivers are always available without conditional autoloading. The module is lightweight (no heavy deps).

---

## Testing Approach

- **`ArrayDriverTest`** — No external infrastructure. Tests cover the full interface: attempt (allow, allow-up-to-max, deny), tooManyAttempts, remainingAttempts, resetAttempts, key isolation.
- **`RedisDriverTest`** — Requires a live Redis instance (available via Docker). Uses Redis database `2` to avoid colliding with application data. Tests are automatically skipped when `ext-redis` is not loaded. `flushDB()` is called in `setUp` and `tearDown`.
- **`CacheDriverTest`** — Uses `ez-php/cache`'s `ArrayDriver` as the backing store — no external infrastructure needed. Covers the same contract surface as `ArrayDriverTest`.
- **`RateLimiterFileDriverTest`** — Uses a temp directory cleaned in `tearDown()`; no external infrastructure. Covers the same contract surface as `ArrayDriverTest`, plus persistence across driver instances and key isolation for filesystem-unsafe keys. **The class is named `RateLimiterFileDriverTest`, not `FileDriverTest`, deliberately:** the root `phpunit.xml` aggregates every module's tests and they all share the `Tests\` namespace, so a plain `Tests\FileDriverTest` collides with `modules/logging`'s at load time — a fatal error, not a test failure. Do not "tidy" the prefix away.
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
