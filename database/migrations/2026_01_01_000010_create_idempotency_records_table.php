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

        Schema::create($prefix.'idempotency_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key', 255);
            $table->string('scope', 32);
            $table->string('result_type', 128)->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->json('result_payload')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at');

            $table->unique(['key', 'scope'], 'ir_key_scope_unique');
            $table->index('expires_at', 'ir_expires_idx');
        });
    }

    public function down(): void
    {
        $prefix = config('usage-limiter.table_prefix', 'ul_');
        Schema::dropIfExists($prefix.'idempotency_records');
    }
};
