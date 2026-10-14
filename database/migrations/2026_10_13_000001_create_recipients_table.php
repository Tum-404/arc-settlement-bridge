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
        Schema::create('recipients', function (Blueprint $table): void {
            $table->id();
            $table->string('client_id')->index();
            $table->string('client_name')->nullable();
            $table->string('crypto_address');
            $table->string('network')->default('ARC-TESTNET');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['client_id', 'network']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipients');
    }
};
