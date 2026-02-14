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

        Schema::create($prefix.'plan_metric_limits', function (Blueprint $table) use ($prefix) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('plan_id');
            $table->string('metric_code', 64);
            $table->unsignedBigInteger('included_amount')->default(0);
            $table->boolean('overage_enabled')->default(false);
            $table->unsignedBigInteger('overage_unit_size')->nullable();
            $table->unsignedBigInteger('overage_price_cents')->nullable();
            $table->string('pricing_mode', 16)->default('postpaid');
            $table->string('enforcement_mode', 8)->default('hard');
            $table->unsignedBigInteger('max_overage_amount')->nullable();
            $table->string('hybrid_overflow_mode', 16)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'metric_code']);
            $table->foreign('plan_id')
                ->references('id')
                ->on($prefix.'plans')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $prefix = config('usage-limiter.table_prefix', 'ul_');
        Schema::dropIfExists($prefix.'plan_metric_limits');
    }
};
