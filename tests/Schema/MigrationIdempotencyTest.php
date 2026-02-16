<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Schema;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Moneo\UsageLimiter\Tests\TestCase;

/**
 * Verify that migrations can be run repeatedly without errors
 * and that migrate:fresh produces a clean state.
 */
class MigrationIdempotencyTest extends TestCase
{
    public function test_migrations_run_fresh_without_error(): void
    {
        // The base TestCase already runs migrations via defineDatabaseMigrations.
        // Running migrate:fresh should re-create all tables cleanly.
        Artisan::call('migrate:fresh', [
            '--database' => 'testing',
            '--path' => realpath(__DIR__.'/../../database/migrations'),
            '--realpath' => true,
        ]);

        $this->assertTrue(Schema::connection('testing')->hasTable('ul_plans'));
        $this->assertTrue(Schema::connection('testing')->hasTable('ul_usage_reservations'));
        $this->assertTrue(Schema::connection('testing')->hasTable('ul_billing_transactions'));
    }

    public function test_running_migrate_after_fresh_does_not_error(): void
    {
        Artisan::call('migrate:fresh', [
            '--database' => 'testing',
            '--path' => realpath(__DIR__.'/../../database/migrations'),
            '--realpath' => true,
        ]);

        // Running migrate again should succeed (nothing to migrate)
        $exitCode = Artisan::call('migrate', [
            '--database' => 'testing',
            '--path' => realpath(__DIR__.'/../../database/migrations'),
            '--realpath' => true,
        ]);

        $this->assertEquals(0, $exitCode);
    }

    public function test_all_tables_exist_after_migration(): void
    {
        $expectedTables = [
            'ul_plans',
            'ul_plan_metric_limits',
            'ul_billing_accounts',
            'ul_billing_account_plan_assignments',
            'ul_billing_account_metric_overrides',
            'ul_usage_period_aggregates',
            'ul_usage_reservations',
            'ul_usage_overages',
            'ul_billing_transactions',
            'ul_idempotency_records',
        ];

        foreach ($expectedTables as $table) {
            $this->assertTrue(
                Schema::connection('testing')->hasTable($table),
                "Table '{$table}' does not exist after migration",
            );
        }
    }
}
