<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Commands;

use Illuminate\Console\Command;
use Moneo\UsageLimiter\Contracts\PlanResolver;
use Moneo\UsageLimiter\Models\UsageOverage;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;

class RecalculateOveragesCommand extends Command
{
    protected $signature = 'usage:recalculate-overages
                            {--period= : Specific period_start date (YYYY-MM-DD)}';

    protected $description = 'Recompute overage amounts and prices from actual committed usage vs included amounts';

    public function handle(PlanResolver $planResolver): int
    {
        $this->info('Recalculating overages...');

        $query = UsageOverage::query();

        if ($periodStart = $this->option('period')) {
            $query->where('period_start', $periodStart);
        }

        $corrected = 0;

        $query->chunk(100, function ($overages) use ($planResolver, &$corrected) {
            foreach ($overages as $overage) {
                $metricLimit = $planResolver->resolveMetric(
                    $overage->billing_account_id,
                    $overage->metric_code,
                );

                if ($metricLimit === null) {
                    continue;
                }

                // Get actual committed usage
                $aggregate = UsagePeriodAggregate::where('billing_account_id', $overage->billing_account_id)
                    ->where('metric_code', $overage->metric_code)
                    ->where('period_start', $overage->period_start)
                    ->first();

                if ($aggregate === null) {
                    continue;
                }

                $actualOverage = max(0, $aggregate->committed_usage - $metricLimit->includedAmount);
                $unitSize = max($metricLimit->overageUnitSize ?? 1, 1);
                $unitPrice = $metricLimit->overagePriceCents ?? 0;
                $expectedPrice = $metricLimit->calculateOverageCost($actualOverage);

                if ($overage->overage_amount !== $actualOverage || $overage->total_price_cents !== $expectedPrice) {
                    $overage->update([
                        'overage_amount' => $actualOverage,
                        'total_price_cents' => $expectedPrice,
                    ]);

                    $corrected++;
                    $this->line(sprintf(
                        '  Corrected: account=%d metric=%s period=%s overage=%d→%d price=%d→%d',
                        $overage->billing_account_id,
                        $overage->metric_code,
                        $overage->period_start->format('Y-m-d'),
                        $overage->getOriginal('overage_amount'),
                        $actualOverage,
                        $overage->getOriginal('total_price_cents'),
                        $expectedPrice,
                    ));
                }
            }
        });

        $this->info("Recalculation complete. Corrected {$corrected} overage record(s).");

        return self::SUCCESS;
    }
}
