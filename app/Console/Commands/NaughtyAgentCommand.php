<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Settlement\Commands\CreateSettlement;
use App\Application\Settlement\Commands\RecoverUnknownSettlements;
use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\Contracts\SettlementProvider;
use App\Domain\Settlement\DTOs\CreateSettlementCommand;
use App\Domain\Settlement\Enums\SettlementEventType;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementEvent;
use App\Domain\Settlement\Services\IdempotencyKeyGenerator;
use App\Domain\Settlement\Services\SettlementStateMachine;
use App\Infrastructure\Providers\FakeSettlementProvider;
use App\Infrastructure\SolidInvoice\FakeInvoiceGateway;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

final class NaughtyAgentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'settlements:naughty-agent
                            {invoiceId=INV-001 : Target invoice ID}
                            {--source=solidinvoice : Source invoicing system}
                            {--gateway= : Invoicing gateway implementation (fake or solidinvoice)}
                            {--provider= : Settlement provider implementation (fake or arc)}
                            {--duplicate=3 : Number of identical requests to fire}
                            {--concurrent : Simulate concurrent submission}
                            {--timeout-simulation : Force a provider network timeout}
                            {--recover : Trigger recovery after timeout}';

    /**
     * The console command description.
     */
    protected $description = 'Simulate rogue/naughty agent behavior (rapid duplicates, retry storms, timeouts, recovery)';

    public function handle(
        CreateSettlement $creator,
        InvoiceGateway $gateway,
        SettlementProvider $provider,
        RecoverUnknownSettlements $recoverer,
    ): int {
        $invoiceId = (string) $this->argument('invoiceId');
        $source = (string) $this->option('source');
        $duplicateCount = max(1, (int) $this->option('duplicate'));
        $isConcurrent = (bool) $this->option('concurrent');
        $simulateTimeout = (bool) $this->option('timeout-simulation');
        $shouldRecover = (bool) $this->option('recover');

        if ($this->option('gateway') === 'fake') {
            $gateway = app(FakeInvoiceGateway::class);
        }
        if ($this->option('provider') === 'fake') {
            $provider = app(FakeSettlementProvider::class);
            $creator = new CreateSettlement(
                app(IdempotencyKeyGenerator::class),
                app(SettlementStateMachine::class),
                $provider,
                $gateway,
            );
        }

        $this->info('=== Naughty Agent Simulator Starting ===');
        $this->line("Invoice: <comment>{$invoiceId}</comment>");
        $this->line("Source: <comment>{$source}</comment>");
        $this->line('Gateway: <comment>'.($this->option('gateway') ?: config('settlement.invoice_gateway')).'</comment>');
        $this->line("Duplicate Count: <comment>{$duplicateCount}</comment>");
        $this->line('Mode: <comment>'.($isConcurrent ? 'Concurrent Hammering' : 'Sequential Requests').'</comment>');

        // Fetch invoice coordinates
        try {
            $invoice = $gateway->get($invoiceId);
        } catch (Throwable $e) {
            $this->error("Failed to fetch invoice [{$invoiceId}]: ".$e->getMessage());

            return 1;
        }

        $command = new CreateSettlementCommand(
            source: $source,
            invoiceId: $invoice->id,
            recipient: $invoice->recipient,
            amount: $invoice->amount,
            currency: $invoice->currency,
        );

        if ($simulateTimeout) {
            $this->warn("Simulating provider connection timeout for invoice [{$invoiceId}]...");
            // Temporarily swap or force connection exception
            $mockProvider = new class implements SettlementProvider
            {
                public function submit(Settlement $settlement): never
                {
                    throw new ConnectionException('cURL error 28: Operation timed out after 5000 milliseconds');
                }

                public function status(string $providerTransactionId): never
                {
                    throw new ConnectionException('Connection timed out');
                }

                public function name(): string
                {
                    return 'simulated-timeout';
                }
            };

            $timeoutCreator = new CreateSettlement(
                app(IdempotencyKeyGenerator::class),
                app(SettlementStateMachine::class),
                $mockProvider,
                $gateway,
            );

            try {
                $timeoutCreator->execute($command);
            } catch (Throwable $e) {
                $this->error('Timeout creator encountered exception: '.$e->getMessage());
            }

            $settlement = Settlement::where('source_invoice_id', $invoice->id)->first();
            $this->line('Settlement status after timeout: <fg=yellow>'.($settlement?->status->value ?? 'none').'</>');

            if ($shouldRecover) {
                $this->info('Triggering recovery command...');
                $recovered = $recoverer->execute();
                $this->info("Recovered {$recovered} settlement(s).");
                $settlement = $settlement?->fresh();
                $this->line('Final settlement status: <fg=green>'.($settlement?->status->value ?? 'none').'</>');
            }

            return 0;
        }

        $results = [];
        $createdCount = 0;
        $duplicateCountRecorded = 0;

        $this->line("Firing {$duplicateCount} identical requests...");

        for ($i = 1; $i <= $duplicateCount; $i++) {
            try {
                $res = $creator->execute($command);
                $isDup = $res['is_duplicate'];
                if ($isDup) {
                    $duplicateCountRecorded++;
                } else {
                    $createdCount++;
                }
                $results[] = [
                    'request' => $i,
                    'status' => 'OK',
                    'is_duplicate' => $isDup ? 'YES' : 'NO',
                    'settlement_id' => $res['settlement']->id,
                    'state' => $res['settlement']->status->value,
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'request' => $i,
                    'status' => 'ERROR',
                    'is_duplicate' => 'N/A',
                    'settlement_id' => 'N/A',
                    'state' => $e->getMessage(),
                ];
            }
        }

        $this->table(['Request #', 'Result', 'Is Duplicate', 'Settlement ID', 'Status'], $results);

        $settlement = Settlement::where('source_invoice_id', $invoice->id)->first();
        if ($settlement !== null) {
            $duplicateEvents = SettlementEvent::where('settlement_id', $settlement->id)
                ->where('type', SettlementEventType::DUPLICATE_REQUEST_RECEIVED)
                ->count();

            $this->newLine();
            $this->info('=== Results & Verification ===');
            $this->line("Settlements Created in DB: <fg=green>{$createdCount}</>");
            $this->line("Duplicate Responses: <fg=green>{$duplicateCountRecorded}</>");
            $this->line("DUPLICATE_REQUEST_RECEIVED Events: <fg=green>{$duplicateEvents}</>");
            $this->line("Settlement Status: <fg=cyan>{$settlement->status->value}</>");
        }

        return 0;
    }
}
