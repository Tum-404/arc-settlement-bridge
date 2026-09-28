<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table): void {
            $table->uuid('provider_idempotency_key')->nullable()->unique()->after('idempotency_key');
            $table->string('reconciliation_status')->default('pending')->after('status');
            $table->timestamp('reconciled_at')->nullable()->after('confirmed_at');
            $table->unique(['source', 'source_invoice_id'], 'settlements_source_invoice_unique');
        });

        Schema::table('provider_webhook_events', function (Blueprint $table): void {
            $table->string('processing_status')->default('pending')->after('payload');
            $table->unsignedInteger('attempts')->default(0)->after('processing_status');
            $table->text('last_error')->nullable()->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('provider_webhook_events', function (Blueprint $table): void {
            $table->dropColumn(['processing_status', 'attempts', 'last_error']);
        });
        Schema::table('settlements', function (Blueprint $table): void {
            $table->dropUnique('settlements_source_invoice_unique');
            $table->dropUnique(['provider_idempotency_key']);
            $table->dropColumn(['provider_idempotency_key', 'reconciliation_status', 'reconciled_at']);
        });
    }
};
