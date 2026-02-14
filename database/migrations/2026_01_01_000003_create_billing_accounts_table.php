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

        Schema::create($prefix.'billing_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('external_id', 128)->unique()->nullable();
            $table->string('name', 255);
            $table->bigInteger('wallet_balance_cents')->default(0);
            $table->char('wallet_currency', 3)->default('USD');
            $table->boolean('auto_topup_enabled')->default(false);
            $table->bigInteger('auto_topup_threshold_cents')->nullable();
            $table->bigInteger('auto_topup_amount_cents')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $prefix = config('usage-limiter.table_prefix', 'ul_');
        Schema::dropIfExists($prefix.'billing_accounts');
    }
};
