<?php

declare(strict_types=1);

namespace Tests;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use RuntimeException;

/**
 * Class RateLimiterConcurrency
 *
 * Runs attempt() from several PHP processes at once against the same key and
 * returns how many attempts were allowed. Each child connects on its own,
 * waits for a shared start time so the calls overlap, then prints its number
 * of allowed attempts.
 *
 * @package Tests
 */
final class RateLimiterConcurrency
{
    /**
     * @param string $driverFactory      PHP expression, evaluated in each child, that returns the RateLimiterInterface.
     * @param string $key
     * @param int    $maxAttempts
     * @param int    $processes
     * @param int    $attemptsPerProcess
     *
     * @return int Total allowed attempts across all processes.
     */
    public static function allowedAttempts(
        string $driverFactory,
        string $key,
        int $maxAttempts,
        int $processes,
        int $attemptsPerProcess,
    ): int {
        $loaderFile = (new ReflectionClass(ClassLoader::class))->getFileName();

        if ($loaderFile === false) {
            throw new RuntimeException('Cannot locate the Composer autoloader.');
        }

        $autoload = dirname($loaderFile, 2) . '/autoload.php';
        $startAt = microtime(true) + 0.5;

        $code = sprintf(
            'require %s; $d = %s;'
            . ' time_nanosleep(0, (int) max(0, (%F - microtime(true)) * 1e9)); $ok = 0;'
            . ' for ($i = 0; $i < %d; $i++) { if ($d->attempt(%s, %d, 60)) { $ok++; } } echo $ok;',
            var_export($autoload, true),
            $driverFactory,
            $startAt,
            $attemptsPerProcess,
            var_export($key, true),
            $maxAttempts,
        );

        return self::run($code, $processes);
    }

    /**
     * PHP expression that builds $driverClass over a fresh Redis connection.
     *
     * @param class-string $driverClass
     * @param string       $host
     * @param int          $port
     * @param int          $database
     *
     * @return string
     */
    public static function redisFactory(string $driverClass, string $host, int $port, int $database): string
    {
        return sprintf(
            '(function () { $r = new Redis(); $r->connect(%s, %d); $r->select(%d); return new \\%s($r); })()',
            var_export($host, true),
            $port,
            $database,
            ltrim($driverClass, '\\'),
        );
    }

    /**
     * @param string $code
     * @param int    $processes
     *
     * @return int
     */
    private static function run(string $code, int $processes): int
    {
        $pipes = [];
        $handles = [];

        for ($i = 0; $i < $processes; $i++) {
            $handle = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);

            if ($handle === false) {
                throw new RuntimeException('proc_open failed.');
            }

            $handles[$i] = $handle;
        }

        $allowed = 0;

        foreach ($handles as $i => $handle) {
            $out = (string) stream_get_contents($pipes[$i][1]);
            $err = (string) stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($handle);

            if (!ctype_digit(trim($out))) {
                throw new RuntimeException('Child process failed: ' . $out . $err);
            }

            $allowed += (int) trim($out);
        }

        return $allowed;
    }
}
