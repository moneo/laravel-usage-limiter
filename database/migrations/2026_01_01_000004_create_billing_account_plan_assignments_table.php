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

        Schema::create($prefix.'billing_account_plan_assignments', function (Blueprint $table) use ($prefix) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('billing_account_id');
            $table->unsignedBigInteger('plan_id');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['billing_account_id', 'ended_at'], 'bapa_account_ended_idx');
            $table->foreign('billing_account_id', 'bapa_billing_account_id_fk')
                ->references('id')
                ->on($prefix.'billing_accounts')
                ->onDelete('cascade');
            $table->foreign('plan_id', 'bapa_plan_id_fk')
                ->references('id')
                ->on($prefix.'plans')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $prefix = config('usage-limiter.table_prefix', 'ul_');
        Schema::dropIfExists($prefix.'billing_account_plan_assignments');
    }
};
