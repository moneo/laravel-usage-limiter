<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Helpers;

use Symfony\Component\Process\Process;

/**
 * Spawns N parallel PHP worker processes for concurrency testing.
 *
 * Each worker runs a standalone PHP script that bootstraps the Laravel app
 * (via testbench), performs an operation, and prints JSON results to stdout.
 * The parent test collects and asserts on the results.
 */
final readonly class ConcurrencyRunner
{
    /**
     * Run N worker processes in parallel and collect results.
     *
     * @param  string  $scriptPath  Path to the PHP worker script
     * @param  int  $workerCount  Number of parallel workers to spawn
     * @param  array<string, string>  $env  Environment variables passed to each worker
     * @param  int  $timeoutSeconds  Per-worker timeout
     * @return list<array{exitCode: int, output: string, error: string, parsed: ?array<string, mixed>}>
     */
    public static function run(
        string $scriptPath,
        int $workerCount,
        array $env = [],
        int $timeoutSeconds = 30,
    ): array {
        $processes = [];

        for ($i = 0; $i < $workerCount; $i++) {
            $proc = new Process(
                command: [PHP_BINARY, $scriptPath],
                env: array_merge($env, [
                    'WORKER_ID' => (string) $i,
                ]),
                timeout: $timeoutSeconds,
            );
            $proc->start();
            $processes[$i] = $proc;
        }

        $results = [];
        foreach ($processes as $i => $proc) {
            $proc->wait();

            $output = trim($proc->getOutput());
            $parsed = null;
            if ($output !== '') {
                $decoded = json_decode($output, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $parsed = $decoded;
                }
            }

            $results[] = [
                'exitCode' => $proc->getExitCode(),
                'output' => $output,
                'error' => $proc->getErrorOutput(),
                'parsed' => $parsed,
            ];
        }

        return $results;
    }

    /**
     * Count how many workers returned success=true in their JSON output.
     *
     * @param  list<array{exitCode: int, output: string, error: string, parsed: ?array<string, mixed>}>  $results
     */
    public static function countSuccesses(array $results): int
    {
        return count(array_filter($results, fn (array $r): bool => ($r['parsed']['success'] ?? false) === true));
    }

    /**
     * Count how many workers returned success=false in their JSON output.
     *
     * @param  list<array{exitCode: int, output: string, error: string, parsed: ?array<string, mixed>}>  $results
     */
    public static function countFailures(array $results): int
    {
        return count(array_filter($results, fn (array $r): bool => ($r['parsed']['success'] ?? true) === false));
    }
}
