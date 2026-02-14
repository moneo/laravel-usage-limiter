<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Schema;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Moneo\UsageLimiter\Models\BillingAccount;
use Moneo\UsageLimiter\Models\BillingTransaction;
use Moneo\UsageLimiter\Models\IdempotencyRecord;
use Moneo\UsageLimiter\Models\Plan;
use Moneo\UsageLimiter\Models\PlanMetricLimit;
use Moneo\UsageLimiter\Models\UsageOverage;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Models\UsageReservation;
use Moneo\UsageLimiter\Tests\TestCase;

class MigrationSchemaTest extends TestCase
{
    // ---------------------------------------------------------------
    // A1: All expected tables exist
    // ---------------------------------------------------------------

    public function test_all_package_tables_exist(): void
    {
        $tables = [
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

        foreach ($tables as $table) {
            $this->assertTrue(
                Schema::connection('testing')->hasTable($table),
                "Table '{$table}' does not exist",
            );
        }
    }

    // ---------------------------------------------------------------
    // A2: Critical columns exist on each table
    // ---------------------------------------------------------------

    public function test_plans_table_has_expected_columns(): void
    {
        $columns = Schema::connection('testing')->getColumnListing('ul_plans');
        $this->assertContains('id', $columns);
        $this->assertContains('code', $columns);
        $this->assertContains('name', $columns);
        $this->assertContains('is_active', $columns);
        $this->assertContains('metadata', $columns);
    }

    public function test_billing_accounts_has_wallet_columns(): void
    {
        $columns = Schema::connection('testing')->getColumnListing('ul_billing_accounts');
        $this->assertContains('wallet_balance_cents', $columns);
        $this->assertContains('wallet_currency', $columns);
        $this->assertContains('auto_topup_enabled', $columns);
        $this->assertContains('auto_topup_threshold_cents', $columns);
        $this->assertContains('auto_topup_amount_cents', $columns);
    }

    public function test_usage_period_aggregates_has_counter_columns(): void
    {
        $columns = Schema::connection('testing')->getColumnListing('ul_usage_period_aggregates');
        $this->assertContains('committed_usage', $columns);
        $this->assertContains('reserved_usage', $columns);
        $this->assertContains('billing_account_id', $columns);
        $this->assertContains('metric_code', $columns);
        $this->assertContains('period_start', $columns);
        $this->assertContains('period_end', $columns);
    }

    public function test_usage_reservations_has_required_columns(): void
    {
        $columns = Schema::connection('testing')->getColumnListing('ul_usage_reservations');
        $expected = [
            'id', 'ulid', 'billing_account_id', 'metric_code', 'period_start',
            'amount', 'idempotency_key', 'status', 'reserved_at', 'committed_at',
            'released_at', 'expires_at',
        ];

        foreach ($expected as $col) {
            $this->assertContains($col, $columns, "Column '{$col}' missing from ul_usage_reservations");
        }
    }

    public function test_billing_transactions_has_required_columns(): void
    {
        $columns = Schema::connection('testing')->getColumnListing('ul_billing_transactions');
        $expected = [
            'id', 'billing_account_id', 'type', 'amount_cents',
            'balance_after_cents', 'idempotency_key', 'reference_type', 'reference_id',
        ];

        foreach ($expected as $col) {
            $this->assertContains($col, $columns, "Column '{$col}' missing from ul_billing_transactions");
        }
    }

    // ---------------------------------------------------------------
    // A3: Unique constraints reject duplicates
    // ---------------------------------------------------------------

    public function test_plan_code_unique_constraint(): void
    {
        Plan::create(['code' => 'unique_test', 'name' => 'Test']);

        $this->expectException(QueryException::class);
        Plan::create(['code' => 'unique_test', 'name' => 'Test2']);
    }

    public function test_plan_metric_limit_plan_metric_unique(): void
    {
        $plan = Plan::create(['code' => 'p1', 'name' => 'P1']);
        PlanMetricLimit::create([
            'plan_id' => $plan->id,
            'metric_code' => 'api_calls',
            'included_amount' => 100,
            'overage_enabled' => false,
            'pricing_mode' => 'postpaid',
            'enforcement_mode' => 'hard',
        ]);

        $this->expectException(QueryException::class);
        PlanMetricLimit::create([
            'plan_id' => $plan->id,
            'metric_code' => 'api_calls',
            'included_amount' => 200,
            'overage_enabled' => false,
            'pricing_mode' => 'postpaid',
            'enforcement_mode' => 'hard',
        ]);
    }

    public function test_aggregate_unique_constraint(): void
    {
        $account = BillingAccount::create(['name' => 'Test', 'wallet_balance_cents' => 0, 'is_active' => true]);

        UsagePeriodAggregate::create([
            'billing_account_id' => $account->id,
            'metric_code' => 'api_calls',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'committed_usage' => 0,
            'reserved_usage' => 0,
        ]);

        $this->expectException(QueryException::class);
        UsagePeriodAggregate::create([
            'billing_account_id' => $account->id,
            'metric_code' => 'api_calls',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'committed_usage' => 0,
            'reserved_usage' => 0,
        ]);
    }

    public function test_reservation_ulid_unique_constraint(): void
    {
        $account = BillingAccount::create(['name' => 'Test', 'wallet_balance_cents' => 0, 'is_active' => true]);

        UsageReservation::create([
            'ulid' => 'TEST_ULID_12345678901234',
            'billing_account_id' => $account->id,
            'metric_code' => 'api_calls',
            'period_start' => '2026-01-01',
            'amount' => 10,
            'status' => 'pending',
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->expectException(QueryException::class);
        UsageReservation::create([
            'ulid' => 'TEST_ULID_12345678901234',
            'billing_account_id' => $account->id,
            'metric_code' => 'api_calls',
            'period_start' => '2026-01-01',
            'amount' => 5,
            'status' => 'pending',
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    public function test_reservation_idempotency_key_unique_constraint(): void
    {
        $account = BillingAccount::create(['name' => 'Test', 'wallet_balance_cents' => 0, 'is_active' => true]);

        UsageReservation::create([
            'ulid' => 'ULID_A_2345678901234567',
            'billing_account_id' => $account->id,
            'metric_code' => 'api_calls',
            'period_start' => '2026-01-01',
            'amount' => 10,
            'idempotency_key' => 'idem-key-1',
            'status' => 'pending',
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->expectException(QueryException::class);
        UsageReservation::create([
            'ulid' => 'ULID_B_2345678901234567',
            'billing_account_id' => $account->id,
            'metric_code' => 'api_calls',
            'period_start' => '2026-01-01',
            'amount' => 5,
            'idempotency_key' => 'idem-key-1',
            'status' => 'pending',
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    public function test_billing_transaction_idempotency_key_unique(): void
    {
        $account = BillingAccount::create(['name' => 'Test', 'wallet_balance_cents' => 0, 'is_active' => true]);
        $table = (new BillingTransaction)->getTable();
        $connection = DB::connection(config('usage-limiter.database_connection'));

        $connection->table($table)->insert([
            'billing_account_id' => $account->id,
            'type' => 'debit',
            'amount_cents' => -100,
            'balance_after_cents' => -100,
            'idempotency_key' => 'txn-idem-1',
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $connection->table($table)->insert([
            'billing_account_id' => $account->id,
            'type' => 'debit',
            'amount_cents' => -200,
            'balance_after_cents' => -300,
            'idempotency_key' => 'txn-idem-1',
            'created_at' => now(),
        ]);
    }

    public function test_idempotency_record_key_scope_unique(): void
    {
        IdempotencyRecord::create([
            'key' => 'test-key',
            'scope' => 'reserve',
            'expires_at' => now()->addHours(48),
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        IdempotencyRecord::create([
            'key' => 'test-key',
            'scope' => 'reserve',
            'expires_at' => now()->addHours(48),
            'created_at' => now(),
        ]);
    }

    public function test_overage_account_metric_period_unique(): void
    {
        $account = BillingAccount::create(['name' => 'Test', 'wallet_balance_cents' => 0, 'is_active' => true]);

        UsageOverage::create([
            'billing_account_id' => $account->id,
            'metric_code' => 'api_calls',
            'period_start' => '2026-01-01',
            'overage_amount' => 10,
            'overage_unit_size' => 1,
            'unit_price_cents' => 100,
            'total_price_cents' => 1000,
            'settlement_status' => 'pending',
        ]);

        $this->expectException(QueryException::class);
        UsageOverage::create([
            'billing_account_id' => $account->id,
            'metric_code' => 'api_calls',
            'period_start' => '2026-01-01',
            'overage_amount' => 20,
            'overage_unit_size' => 1,
            'unit_price_cents' => 100,
            'total_price_cents' => 2000,
            'settlement_status' => 'pending',
        ]);
    }

    // ---------------------------------------------------------------
    // A4: Foreign key cascades
    // ---------------------------------------------------------------

    public function test_deleting_plan_cascades_to_metric_limits(): void
    {
        // Enable FK enforcement (SQLite requires this pragma)
        DB::connection('testing')->statement('PRAGMA foreign_keys = ON');

        $plan = Plan::create(['code' => 'cascade_test', 'name' => 'Cascade Test']);
        PlanMetricLimit::create([
            'plan_id' => $plan->id,
            'metric_code' => 'api_calls',
            'included_amount' => 100,
            'overage_enabled' => false,
            'pricing_mode' => 'postpaid',
            'enforcement_mode' => 'hard',
        ]);

        $this->assertDatabaseCount('ul_plan_metric_limits', 1);

        // Use raw delete to trigger FK cascade (Eloquent delete may not trigger DB-level cascades)
        DB::connection('testing')->table('ul_plans')->where('id', $plan->id)->delete();

        $this->assertDatabaseCount('ul_plan_metric_limits', 0);
    }

    public function test_deleting_account_cascades_to_plan_assignments(): void
    {
        // Enable FK enforcement (SQLite requires this pragma)
        DB::connection('testing')->statement('PRAGMA foreign_keys = ON');

        $plan = Plan::create(['code' => 'cascade2', 'name' => 'Cascade2']);
        $account = BillingAccount::create(['name' => 'CascadeAcct', 'wallet_balance_cents' => 0, 'is_active' => true]);

        \Moneo\UsageLimiter\Models\BillingAccountPlanAssignment::create([
            'billing_account_id' => $account->id,
            'plan_id' => $plan->id,
            'started_at' => now(),
        ]);

        $this->assertDatabaseCount('ul_billing_account_plan_assignments', 1);

        DB::connection('testing')->table('ul_billing_accounts')->where('id', $account->id)->delete();

        $this->assertDatabaseCount('ul_billing_account_plan_assignments', 0);
    }

    // ---------------------------------------------------------------
    // A5: Default values
    // ---------------------------------------------------------------

    public function test_aggregate_defaults_to_zero_usage(): void
    {
        $account = BillingAccount::create(['name' => 'Test', 'wallet_balance_cents' => 0, 'is_active' => true]);

        $table = (new UsagePeriodAggregate)->getTable();
        DB::connection(config('usage-limiter.database_connection'))->table($table)->insert([
            'billing_account_id' => $account->id,
            'metric_code' => 'api_calls',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(0, $aggregate->committed_usage);
        $this->assertEquals(0, $aggregate->reserved_usage);
    }

    public function test_billing_account_defaults(): void
    {
        $account = BillingAccount::create(['name' => 'Defaults Test']);

        $this->assertEquals(0, $account->fresh()->wallet_balance_cents);
        $this->assertTrue($account->fresh()->is_active);
        $this->assertFalse($account->fresh()->auto_topup_enabled);
    }

    public function test_plan_default_active(): void
    {
        $plan = Plan::create(['code' => 'def', 'name' => 'Default']);
        $this->assertTrue($plan->fresh()->is_active);
    }

    // ---------------------------------------------------------------
    // A6: Nullable fields allow null
    // ---------------------------------------------------------------

    public function test_reservation_idempotency_key_nullable(): void
    {
        $account = BillingAccount::create(['name' => 'Test', 'wallet_balance_cents' => 0, 'is_active' => true]);

        $reservation = UsageReservation::create([
            'ulid' => 'ULID_NULL_TEST_678901234',
            'billing_account_id' => $account->id,
            'metric_code' => 'api_calls',
            'period_start' => '2026-01-01',
            'amount' => 10,
            'idempotency_key' => null,
            'status' => 'pending',
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->assertNull($reservation->fresh()->idempotency_key);
    }

    public function test_billing_account_external_id_nullable(): void
    {
        $account = BillingAccount::create([
            'name' => 'No External',
            'wallet_balance_cents' => 0,
            'is_active' => true,
            'external_id' => null,
        ]);

        $this->assertNull($account->fresh()->external_id);
    }
}
