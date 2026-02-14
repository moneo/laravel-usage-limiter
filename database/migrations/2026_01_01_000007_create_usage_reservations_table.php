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

        Schema::create($prefix.'usage_reservations', function (Blueprint $table) use ($prefix) {
            $table->bigIncrements('id');
            $table->char('ulid', 26)->unique();
            $table->unsignedBigInteger('billing_account_id');
            $table->string('metric_code', 64);
            $table->date('period_start');
            $table->unsignedBigInteger('amount');
            $table->string('idempotency_key', 255)->nullable();
            $table->string('status', 16);
            $table->timestamp('reserved_at');
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expires_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['billing_account_id', 'metric_code', 'period_start', 'status'],
                'ur_account_metric_period_status_idx'
            );
            $table->index(['status', 'expires_at'], 'ur_status_expires_idx');
            $table->unique(
                ['idempotency_key', 'billing_account_id'],
                'ur_idempotency_account_unique'
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
        Schema::dropIfExists($prefix.'usage_reservations');
    }
};
