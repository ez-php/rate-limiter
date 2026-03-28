<?php

declare(strict_types=1);

/**
 * Performance benchmark for EzPhp\RateLimiter\RateLimiter (ArrayDriver).
 *
 * Measures the overhead of rate limit checks (attempt, tooManyAttempts,
 * remainingAttempts) using the in-memory ArrayDriver — no Redis involved.
 *
 * Exits with code 1 if the per-iteration time exceeds the defined threshold,
 * allowing CI to detect performance regressions automatically.
 *
 * Usage:
 *   php benchmarks/check.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use EzPhp\RateLimiter\ArrayDriver;
use EzPhp\RateLimiter\RateLimiter;

const ITERATIONS = 10000;
const OPS_PER_ITER = 3;
const THRESHOLD_MS = 0.5; // per-iteration upper bound in milliseconds

// ── Setup ─────────────────────────────────────────────────────────────────────

$rateLimiter = new RateLimiter(new ArrayDriver());
RateLimiter::setInstance($rateLimiter);

// Warm-up
RateLimiter::attempt('bench:127.0.0.1', 60, 60);
RateLimiter::tooManyAttempts('bench:127.0.0.1', 60);
RateLimiter::remainingAttempts('bench:127.0.0.1', 60);

// ── Benchmark ─────────────────────────────────────────────────────────────────

$start = hrtime(true);

for ($i = 0; $i < ITERATIONS; $i++) {
    $key = 'api:' . ($i % 100); // 100 distinct keys to avoid cache staleness
    RateLimiter::attempt($key, 60, 60);
    RateLimiter::tooManyAttempts($key, 60);
    RateLimiter::remainingAttempts($key, 60);
}

$end = hrtime(true);

$totalMs = ($end - $start) / 1_000_000;
$perIter = $totalMs / ITERATIONS;

echo sprintf(
    "Rate Limiter Benchmark (ArrayDriver)\n" .
    "  Operations per iter  : %d (attempt, tooManyAttempts, remainingAttempts)\n" .
    "  Iterations           : %d\n" .
    "  Total time           : %.2f ms\n" .
    "  Per iteration        : %.3f ms\n" .
    "  Threshold            : %.1f ms\n",
    OPS_PER_ITER,
    ITERATIONS,
    $totalMs,
    $perIter,
    THRESHOLD_MS,
);

if ($perIter > THRESHOLD_MS) {
    echo sprintf(
        "FAIL: %.3f ms exceeds threshold of %.1f ms\n",
        $perIter,
        THRESHOLD_MS,
    );
    exit(1);
}

echo "PASS\n";
exit(0);
