<?php

namespace Database\Seeders;

use App\Domain\Settlement\Models\Recipient;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        Recipient::updateOrCreate(
            ['client_id' => 'Acme Corporation', 'network' => 'ARC-TESTNET'],
            [
                'client_name' => 'Acme Corporation',
                'crypto_address' => '0x71C836643F37D110e49952042125585097486eF8',
                'is_active' => true,
            ]
        );
        Recipient::updateOrCreate(
            ['client_id' => 'Globex Inc', 'network' => 'ARC-TESTNET'],
            [
                'client_name' => 'Globex Inc',
                'crypto_address' => '0x3A2154483B8e72D714C808E1Dea0fDb00f13B9B9',
                'is_active' => true,
            ]
        );
    }
}
