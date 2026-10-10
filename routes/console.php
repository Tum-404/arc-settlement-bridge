<?php

use App\Application\Settlement\Commands\PollSolidInvoiceInvoices;
use App\Application\Settlement\Commands\RecoverUnknownSettlements;
use App\Infrastructure\Arc\CircleClient;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('settlements:recover-unknown', function (RecoverUnknownSettlements $recover): void {
    $this->info('Recovered '.$recover->execute().' unknown settlement(s).');
})->purpose('Safely retry settlements with ambiguous provider submission outcomes');

Artisan::command('settlements:poll-solidinvoice {--limit= : Maximum eligible invoices to ingest}', function (PollSolidInvoiceInvoices $poll): int {
    $result = $poll->execute($this->option('limit') === null ? null : (int) $this->option('limit'));
    $this->info("Poll completed: {$result['submitted']} submitted, {$result['skipped']} skipped, {$result['failed']} failed.");

    return $result['failed'] > 0 ? 1 : 0;
})->purpose('Poll SolidInvoice for payable invoices without modifying SolidInvoice');

Artisan::command('settlements:circle-secret', function (): void {
    $secret = bin2hex(random_bytes(32));
    $this->info('Generated 32-byte Circle Entity Secret (64 hex characters):');
    $this->line("<fg=yellow;options=bold>{$secret}</>");
    $this->newLine();
    $this->comment('Next steps:');
    $this->line("1. Add to .env: CIRCLE_ENTITY_SECRET={$secret}");
    $this->line('2. Register this secret in Circle Console (Developer Settings -> Entity Secret)');
})->purpose('Generate a random 32-byte hex secret for Circle Developer-Controlled Wallets');

Artisan::command('settlements:circle-check', function (CircleClient $circle): int {
    $this->info('=== Checking Circle / Arc Testnet Configuration ===');

    $apiKey = (string) config('settlement.arc.circle_api_key');
    $entitySecret = (string) config('settlement.arc.circle_entity_secret');
    $walletId = (string) config('settlement.arc.wallet_id');
    $blockchain = (string) config('settlement.arc.blockchain');

    $this->line("Blockchain: <info>{$blockchain}</info>");
    $this->line('API Key: '.(empty($apiKey) ? '<fg=red>Not set</>' : '<info>Configured</info> ('.substr($apiKey, 0, 15).'...)'));
    $this->line('Entity Secret: '.(empty($entitySecret) ? '<fg=red>Not set</>' : '<info>Configured</info> ('.strlen($entitySecret).' chars)'));
    $this->line('Wallet ID: '.(empty($walletId) ? '<fg=yellow>Not set</>' : "<info>{$walletId}</info>"));

    $this->newLine();
    $this->line('Testing Circle API connectivity...');

    try {
        $pubKey = $circle->getPublicKey();
        $this->info('✔ Successfully fetched Circle public key from API.');
    } catch (Throwable $e) {
        $this->error('✖ Circle API connection failed: '.$e->getMessage());

        return 1;
    }

    if (! empty($entitySecret)) {
        try {
            $ciphertext = $circle->generateEntitySecretCiphertext();
            if ($ciphertext !== null) {
                $this->info('✔ Successfully generated entitySecretCiphertext via RSA-OAEP SHA-256.');
            }
        } catch (Throwable $e) {
            $this->error('✖ Failed to encrypt entity secret: '.$e->getMessage());

            return 1;
        }
    } else {
        $this->warn('⚠ CIRCLE_ENTITY_SECRET is empty. Live transfers will be rejected by Circle.');
    }

    if (! empty($walletId)) {
        try {
            $wallet = $circle->getWallet($walletId);
            $address = $wallet['address'] ?? 'unknown';
            $this->info("✔ Arc Wallet found: {$address}");
        } catch (Throwable $e) {
            $this->warn('⚠ Could not fetch wallet details: '.$e->getMessage());
        }
    }

    $this->newLine();
    $this->info('Circle / Arc connectivity check complete.');

    return 0;
})->purpose('Verify Circle Developer API key, entity secret encryption, and Arc wallet connectivity');
