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

        Schema::create($prefix.'billing_account_metric_overrides', function (Blueprint $table) use ($prefix) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('billing_account_id');
            $table->string('metric_code', 64);
            $table->unsignedBigInteger('included_amount')->nullable();
            $table->boolean('overage_enabled')->nullable();
            $table->unsignedBigInteger('overage_unit_size')->nullable();
            $table->unsignedBigInteger('overage_price_cents')->nullable();
            $table->string('pricing_mode', 16)->nullable();
            $table->string('enforcement_mode', 8)->nullable();
            $table->unsignedBigInteger('max_overage_amount')->nullable();
            $table->string('hybrid_overflow_mode', 16)->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['billing_account_id', 'metric_code', 'ended_at'], 'bamo_account_metric_ended_idx');
            $table->foreign('billing_account_id', 'bamo_billing_account_id_fk')
                ->references('id')
                ->on($prefix.'billing_accounts')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $prefix = config('usage-limiter.table_prefix', 'ul_');
        Schema::dropIfExists($prefix.'billing_account_metric_overrides');
    }
};
