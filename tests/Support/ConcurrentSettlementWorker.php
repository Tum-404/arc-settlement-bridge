<?php

declare(strict_types=1);

use App\Application\Settlement\Commands\CreateSettlement;
use App\Domain\Settlement\DTOs\CreateSettlementCommand;
use App\Domain\Settlement\Models\Recipient;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementEvent;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$options = getopt('', ['mode:', 'invoice:', 'barrier::']);
$mode = $options['mode'] ?? '';
$invoiceId = $options['invoice'] ?? '';

if (! is_string($mode) || ! is_string($invoiceId) || $mode === '' || $invoiceId === '') {
    fwrite(STDERR, "Worker requires --mode and --invoice.\n");
    exit(2);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config()->set('settlement.provider', 'fake');
config()->set('settlement.invoice_gateway', 'fake');

try {
    if ($mode === 'seed') {
        Recipient::updateOrCreate(
            ['client_id' => 'Default Client', 'network' => 'ARC-TESTNET'],
            [
                'client_name' => 'Default Client',
                'crypto_address' => '0x71C836643F37D110e49952042125585097486eF8',
                'is_active' => true,
            ],
        );
    } elseif ($mode === 'settle') {
        $barrier = $options['barrier'] ?? null;
        if (! is_string($barrier) || $barrier === '') {
            throw new RuntimeException('Settlement worker requires --barrier.');
        }

        touch($barrier.'.'.getmypid().'.waiting');
        $deadline = microtime(true) + 10;
        while (! file_exists($barrier)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for the settlement concurrency barrier.');
            }
            usleep(10_000);
        }

        app(CreateSettlement::class)->execute(new CreateSettlementCommand(
            source: 'solidinvoice',
            invoiceId: $invoiceId,
            recipient: '0x71C836643F37D110e49952042125585097486eF8',
            amount: '100.000000',
            currency: 'USDC',
        ));
    } elseif ($mode === 'cleanup') {
        $settlementIds = Settlement::query()
            ->where('source', 'solidinvoice')
            ->where('source_invoice_id', $invoiceId)
            ->pluck('id');

        SettlementEvent::query()->whereIn('settlement_id', $settlementIds)->delete();
        Settlement::query()->whereIn('id', $settlementIds)->delete();
    } else {
        throw new RuntimeException("Unsupported worker mode [{$mode}].");
    }

    echo json_encode(['status' => 'ok', 'mode' => $mode], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
}
