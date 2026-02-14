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

        Schema::create($prefix.'billing_transactions', function (Blueprint $table) use ($prefix) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('billing_account_id');
            $table->string('type', 16);
            $table->bigInteger('amount_cents');
            $table->bigInteger('balance_after_cents');
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key', 255)->unique()->nullable();
            $table->string('description', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(
                ['billing_account_id', 'created_at'],
                'bt_account_created_idx'
            );
            $table->index(
                ['reference_type', 'reference_id'],
                'bt_reference_idx'
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
        Schema::dropIfExists($prefix.'billing_transactions');
    }
};
