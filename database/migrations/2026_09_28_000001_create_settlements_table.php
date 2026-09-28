<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table): void {
            $table->id();

            $table->string('source');
            $table->string('source_invoice_id');

            $table->string('recipient');
            $table->decimal('amount', 30, 6);
            $table->string('currency', 10);

            $table->string('idempotency_key')->unique();

            $table->string('status')->default('created');
            $table->string('provider')->nullable();

            $table->string('provider_transaction_id')->nullable()->unique();
            $table->string('tx_hash')->nullable()->unique();

            $table->string('failure_code')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            $table->index(['source', 'source_invoice_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
