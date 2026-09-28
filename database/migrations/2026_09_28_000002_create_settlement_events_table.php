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
        Schema::create('settlement_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('settlement_id')
                ->constrained('settlements')
                ->restrictOnDelete();

            $table->string('type');
            $table->json('payload')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['settlement_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settlement_events');
    }
};
