<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration;

use Illuminate\Database\QueryException;
use Moneo\UsageLimiter\Contracts\IdempotencyStore;
use Moneo\UsageLimiter\Contracts\WalletRepository;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Entrypoints\EventIngestor;
use Moneo\UsageLimiter\Models\BillingTransaction;
use Moneo\UsageLimiter\Models\IdempotencyRecord;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Models\UsageReservation;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use AssertsInvariants;
    use CreatesTestFixtures;

    private UsageLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = app(UsageLimiter::class);
    }

    // ---------------------------------------------------------------
    // I1: Reservation idempotency_key DB uniqueness
    // ---------------------------------------------------------------

    public function test_reservation_idempotency_key_db_unique(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        UsageReservation::create([
            'ulid' => 'ULID_A_IDEM_TEST_1234567',
            'billing_account_id' => $account->id,
            'metric_code' => 'api_calls',
            'period_start' => '2026-01-01',
            'amount' => 10,
            'idempotency_key' => 'unique-idem-key',
            'status' => 'pending',
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->expectException(QueryException::class);

        UsageReservation::create([
            'ulid' => 'ULID_B_IDEM_TEST_1234567',
            'billing_account_id' => $account->id,
            'metric_code' => 'api_calls',
            'period_start' => '2026-01-01',
            'amount' => 5,
            'idempotency_key' => 'unique-idem-key',
            'status' => 'pending',
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    // ---------------------------------------------------------------
    // I2: Billing transaction idempotency_key DB uniqueness
    // ---------------------------------------------------------------

    public function test_billing_transaction_idempotency_key_db_unique(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        $walletRepo = app(WalletRepository::class);
        $walletRepo->atomicCredit($account->id, 1000, 'credit-idem-1');

        $this->expectException(QueryException::class);

        // Try to insert duplicate via raw DB
        BillingTransaction::create([
            'billing_account_id' => $account->id,
            'type' => 'credit',
            'amount_cents' => 500,
            'balance_after_cents' => 1500,
            'idempotency_key' => 'credit-idem-1',
            'created_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // I3: Idempotency record (key, scope) uniqueness
    // ---------------------------------------------------------------

    public function test_idempotency_record_key_scope_unique(): void
    {
        $store = app(IdempotencyStore::class);
        $store->store('test-key', 'reserve');

        // Same key + scope should return existing (not throw)
        $result = $store->store('test-key', 'reserve');
        $this->assertNotNull($result);

        // Only 1 record
        $count = IdempotencyRecord::where('key', 'test-key')->where('scope', 'reserve')->count();
        $this->assertEquals(1, $count);
    }

    // ---------------------------------------------------------------
    // I4: Reserve idempotency: same key -> same ULID, no extra reservation
    // ---------------------------------------------------------------

    public function test_reserve_idempotency_no_extra_reservation(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        $attempt = new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 50,
            idempotencyKey: 'reserve-idem-test',
        );

        $res1 = $this->limiter->reserve($attempt);
        $res2 = $this->limiter->reserve($attempt);
        $res3 = $this->limiter->reserve($attempt);

        $this->assertEquals($res1->ulid, $res2->ulid);
        $this->assertEquals($res1->ulid, $res3->ulid);

        // Only 1 reservation
        $this->assertDatabaseCount('ul_usage_reservations', 1);

        // Reserved usage = 50 (not 150)
        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(50, $aggregate->reserved_usage);

        $this->assertAggregateMatchesReservations(
            $account->id,
            'api_calls',
            $aggregate->period_start->format('Y-m-d'),
        );
    }

    // ---------------------------------------------------------------
    // I5: Commit idempotency: double commit -> no double charge
    // ---------------------------------------------------------------

    public function test_commit_idempotency_no_double_charge(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 0,
            pricingMode: 'prepaid',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 50000);

        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 10,
        ));

        $this->limiter->commit($res->ulid);
        $this->limiter->commit($res->ulid);

        // Only 1 debit
        $debitCount = BillingTransaction::where('billing_account_id', $account->id)
            ->where('type', 'debit')
            ->count();
        $this->assertEquals(1, $debitCount);

        // Wallet debited once: 10 * 100 = 1000 cents
        $account->refresh();
        $this->assertEquals(50000 - 1000, $account->wallet_balance_cents);

        $this->assertNoDuplicateDebits($account->id);
    }

    // ---------------------------------------------------------------
    // I6: Wallet debit idempotency
    // ---------------------------------------------------------------

    public function test_wallet_debit_idempotency(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 10000);

        $walletRepo = app(WalletRepository::class);

        $result1 = $walletRepo->atomicDebit($account->id, 500, 'debit-idem-1');
        $result2 = $walletRepo->atomicDebit($account->id, 500, 'debit-idem-1');

        $this->assertTrue($result1);
        $this->assertTrue($result2); // Idempotent success

        // Wallet debited only once
        $account->refresh();
        $this->assertEquals(10000 - 500, $account->wallet_balance_cents);

        $this->assertIdempotencyKeyUniqueness();
    }

    // ---------------------------------------------------------------
    // I7: Wallet credit idempotency
    // ---------------------------------------------------------------

    public function test_wallet_credit_idempotency(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 0);

        $walletRepo = app(WalletRepository::class);

        $result1 = $walletRepo->atomicCredit($account->id, 1000, 'credit-idem-1');
        $result2 = $walletRepo->atomicCredit($account->id, 1000, 'credit-idem-1');

        $this->assertTrue($result1);
        $this->assertTrue($result2);

        // Wallet credited only once
        $account->refresh();
        $this->assertEquals(1000, $account->wallet_balance_cents);
    }

    // ---------------------------------------------------------------
    // I8: EventIngestor with idempotency_key
    // ---------------------------------------------------------------

    public function test_event_ingestor_idempotency(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        $ingestor = app(EventIngestor::class);

        $result1 = $ingestor->ingest($account->id, 'api_calls', 10, 'ingest-idem-1');
        $result2 = $ingestor->ingest($account->id, 'api_calls', 10, 'ingest-idem-1');

        $this->assertTrue($result1->committed);
        $this->assertTrue($result2->committed);

        // Only 1 reservation, committed_usage = 10
        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(10, $aggregate->committed_usage);
    }

    // ---------------------------------------------------------------
    // I9: Cleanup purges only expired records
    // ---------------------------------------------------------------

    public function test_cleanup_purges_only_expired(): void
    {
        $store = app(IdempotencyStore::class);

        // Create an expired record
        $store->store('expired-key', 'test', ttlHours: 0);
        // Manually set expires_at in the past
        IdempotencyRecord::where('key', 'expired-key')->update(['expires_at' => now()->subHour()]);

        // Create a fresh record
        $store->store('fresh-key', 'test', ttlHours: 48);

        $deleted = $store->cleanup(\Carbon\CarbonImmutable::now());

        $this->assertEquals(1, $deleted);
        $this->assertDatabaseMissing('ul_idempotency_records', ['key' => 'expired-key']);
        $this->assertDatabaseHas('ul_idempotency_records', ['key' => 'fresh-key']);
    }

    // ---------------------------------------------------------------
    // I10: Different scopes allow same key
    // ---------------------------------------------------------------

    public function test_same_key_different_scopes_allowed(): void
    {
        $store = app(IdempotencyStore::class);

        $r1 = $store->store('shared-key', 'reserve');
        $r2 = $store->store('shared-key', 'commit');

        $this->assertNotNull($r1);
        $this->assertNotNull($r2);

        $count = IdempotencyRecord::where('key', 'shared-key')->count();
        $this->assertEquals(2, $count);
    }
}
