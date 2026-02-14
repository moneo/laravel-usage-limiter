<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('usage-limiter.table_prefix', 'ul_');

        Schema::create($prefix.'usage_overages', function (Blueprint $table) use ($prefix) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('billing_account_id');
            $table->string('metric_code', 64);
            $table->date('period_start');
            $table->unsignedBigInteger('overage_amount')->default(0);
            $table->unsignedBigInteger('overage_unit_size');
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedBigInteger('total_price_cents')->default(0);
            $table->string('settlement_status', 16)->default('pending');
            $table->timestamp('invoiced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['billing_account_id', 'metric_code', 'period_start'],
                'uo_account_metric_period_unique'
            );
            $table->index(
                ['settlement_status', 'billing_account_id'],
                'uo_settlement_account_idx'
            );
            $table->foreign('billing_account_id')
                ->references('id')
                ->on($prefix.'billing_accounts')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        $prefix = config('usage-limiter.table_prefix', 'ul_');
        Schema::dropIfExists($prefix.'usage_overages');
    }
};
