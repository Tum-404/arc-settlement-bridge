<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipients', function (Blueprint $table): void {
            $table->string('crypto_address_normalized')->default('');
        });

        DB::table('recipients')->orderBy('id')->each(function (object $recipient): void {
            DB::table('recipients')->where('id', $recipient->id)->update([
                'crypto_address_normalized' => strtolower(trim((string) $recipient->crypto_address)),
            ]);
        });

        $duplicates = DB::table('recipients')
            ->select('crypto_address_normalized')
            ->groupBy('crypto_address_normalized')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('crypto_address_normalized');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Cannot enforce unique wallet addresses while duplicate recipient wallets exist: '.$duplicates->implode(', '));
        }

        Schema::table('recipients', function (Blueprint $table): void {
            $table->unique('crypto_address_normalized');
        });
    }

    public function down(): void
    {
        Schema::table('recipients', function (Blueprint $table): void {
            $table->dropUnique(['crypto_address_normalized']);
            $table->dropColumn('crypto_address_normalized');
        });
    }
};
