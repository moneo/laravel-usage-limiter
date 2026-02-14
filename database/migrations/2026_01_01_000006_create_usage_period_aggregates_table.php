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

        Schema::create($prefix.'usage_period_aggregates', function (Blueprint $table) use ($prefix) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('billing_account_id');
            $table->string('metric_code', 64);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('committed_usage')->default(0);
            $table->unsignedBigInteger('reserved_usage')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['billing_account_id', 'metric_code', 'period_start'],
                'upa_account_metric_period_unique'
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
        Schema::dropIfExists($prefix.'usage_period_aggregates');
    }
};
