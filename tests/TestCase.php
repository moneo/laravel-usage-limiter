<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests;

use Moneo\UsageLimiter\UsageLimiterServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            UsageLimiterServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'UsageLimiter' => \Moneo\UsageLimiter\Support\UsageLimiterFacade::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $driver = (string) env('DB_CONNECTION', 'sqlite');

        $app['config']->set('database.default', 'testing');

        if ($driver === 'mysql') {
            $app['config']->set('database.connections.testing', [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int) env('DB_PORT', 3306),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => null,
            ]);
        } elseif ($driver === 'pgsql') {
            $app['config']->set('database.connections.testing', [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int) env('DB_PORT', 5432),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'sslmode' => 'prefer',
            ]);
        } else {
            $app['config']->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => env('DB_DATABASE', ':memory:'),
                'prefix' => '',
            ]);
        }

        $app['config']->set('usage-limiter.database_connection', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Helper: Create a plan with metric limits.
     */
    protected function createPlanWithLimits(
        string $code = 'test_plan',
        array $metrics = [],
    ): \Moneo\UsageLimiter\Models\Plan {
        $plan = \Moneo\UsageLimiter\Models\Plan::create([
            'code' => $code,
            'name' => ucfirst($code),
            'is_active' => true,
        ]);

        foreach ($metrics as $metricCode => $config) {
            \Moneo\UsageLimiter\Models\PlanMetricLimit::create(array_merge([
                'plan_id' => $plan->id,
                'metric_code' => $metricCode,
                'included_amount' => 1000,
                'overage_enabled' => false,
                'pricing_mode' => 'postpaid',
                'enforcement_mode' => 'hard',
            ], $config));
        }

        return $plan;
    }

    /**
     * Helper: Create a billing account and assign it to a plan.
     */
    protected function createAccountWithPlan(
        \Moneo\UsageLimiter\Models\Plan $plan,
        array $accountOverrides = [],
    ): \Moneo\UsageLimiter\Models\BillingAccount {
        $account = \Moneo\UsageLimiter\Models\BillingAccount::create(array_merge([
            'name' => 'Test Account',
            'wallet_balance_cents' => 0,
            'is_active' => true,
        ], $accountOverrides));

        \Moneo\UsageLimiter\Models\BillingAccountPlanAssignment::create([
            'billing_account_id' => $account->id,
            'plan_id' => $plan->id,
            'started_at' => now(),
        ]);

        // Invalidate plan cache
        app(\Moneo\UsageLimiter\Contracts\PlanResolver::class)->invalidateCache($account->id);

        return $account;
    }
}
