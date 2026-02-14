<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Moneo\UsageLimiter\Contracts\UsageRepository;
use Moneo\UsageLimiter\Enums\ReservationStatus;
use Moneo\UsageLimiter\Events\ReconciliationDivergenceDetected;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;

class ReconcileUsageCommand extends Command
{
    protected $signature = 'usage:reconcile
                            {--auto-correct : Automatically correct divergent aggregates}
                            {--period= : Specific period_start date to reconcile (YYYY-MM-DD)}';

    protected $description = 'Reconcile usage aggregates against reservation records to detect and fix drift';

    public function handle(UsageRepository $repository): int
    {
        $autoCorrect = $this->option('auto-correct')
            || config('usage-limiter.reconciliation.auto_correct', false);

        $this->info('Starting usage reconciliation...');

        $query = UsagePeriodAggregate::query();

        if ($periodStart = $this->option('period')) {
            $query->where('period_start', $periodStart);
        } else {
            // Default: reconcile current and previous period
            // Use startOfMonth() before subMonth() to avoid overflow on the 29th-31st
            $query->where('period_start', '>=', now('UTC')->startOfMonth()->subMonth()->format('Y-m-d'));
        }

        $divergenceCount = 0;

        $query->chunk(100, function ($aggregates) use ($repository, $autoCorrect, &$divergenceCount) {
            foreach ($aggregates as $aggregate) {
                $actualCommitted = $repository->sumReservationsByStatus(
                    $aggregate->billing_account_id,
                    $aggregate->metric_code,
                    $aggregate->period_start->format('Y-m-d'),
                    ReservationStatus::Committed,
                );

                $actualReserved = $repository->sumReservationsByStatus(
                    $aggregate->billing_account_id,
                    $aggregate->metric_code,
                    $aggregate->period_start->format('Y-m-d'),
                    ReservationStatus::Pending,
                );

                $committedDivergence = abs($aggregate->committed_usage - $actualCommitted);
                $reservedDivergence = abs($aggregate->reserved_usage - $actualReserved);

                if ($committedDivergence > 0 || $reservedDivergence > 0) {
                    $divergenceCount++;

                    $this->warn(sprintf(
                        'Divergence: account=%d metric=%s period=%s committed=%d(actual:%d) reserved=%d(actual:%d)',
                        $aggregate->billing_account_id,
                        $aggregate->metric_code,
                        $aggregate->period_start->format('Y-m-d'),
                        $aggregate->committed_usage,
                        $actualCommitted,
                        $aggregate->reserved_usage,
                        $actualReserved,
                    ));

                    if ($committedDivergence > 0) {
                        event(new ReconciliationDivergenceDetected(
                            billingAccountId: $aggregate->billing_account_id,
                            metricCode: $aggregate->metric_code,
                            periodStart: $aggregate->period_start->format('Y-m-d'),
                            type: 'committed_usage',
                            expected: $actualCommitted,
                            actual: $aggregate->committed_usage,
                            corrected: $autoCorrect,
                        ));
                    }

                    if ($reservedDivergence > 0) {
                        event(new ReconciliationDivergenceDetected(
                            billingAccountId: $aggregate->billing_account_id,
                            metricCode: $aggregate->metric_code,
                            periodStart: $aggregate->period_start->format('Y-m-d'),
                            type: 'reserved_usage',
                            expected: $actualReserved,
                            actual: $aggregate->reserved_usage,
                            corrected: $autoCorrect,
                        ));
                    }

                    if ($autoCorrect) {
                        $table = $aggregate->getTable();
                        $affected = DB::connection(config('usage-limiter.database_connection'))
                            ->table($table)
                            ->where('id', $aggregate->id)
                            ->where('committed_usage', $aggregate->committed_usage)
                            ->where('reserved_usage', $aggregate->reserved_usage)
                            ->update([
                                'committed_usage' => $actualCommitted,
                                'reserved_usage' => $actualReserved,
                                'updated_at' => now('UTC'),
                            ]);

                        if ($affected === 0) {
                            $this->warn("  → Skipped correction for aggregate #{$aggregate->id} (changed concurrently)");
                        } else {
                            $this->info("  → Corrected aggregate #{$aggregate->id}");
                        }
                    }
                }
            }
        });

        $this->info("Reconciliation complete. Divergences found: {$divergenceCount}");

        return $divergenceCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
