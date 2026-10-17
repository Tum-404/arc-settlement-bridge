<?php

declare(strict_types=1);

use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\DTOs\ExternalInvoice;
use App\Domain\Settlement\DTOs\SettlementReceipt;
use App\Domain\Settlement\Enums\SettlementEventType;
use App\Domain\Settlement\Enums\SettlementStatus;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementEvent;
use App\Infrastructure\SolidInvoice\FakeInvoiceGateway;

beforeEach(function (): void {
    FakeInvoiceGateway::reset();
});

test('poller ingests each eligible SolidInvoice invoice once and skips its active lifecycle', function (): void {
    $this->artisan('settlements:poll-solidinvoice')->assertExitCode(0);

    expect(Settlement::count())->toBe(2)
        ->and(Settlement::where('status', 'submitted')->count())->toBe(2);

    $this->artisan('settlements:poll-solidinvoice')->assertExitCode(0);

    expect(Settlement::count())->toBe(2);
});

test('poller handles an unavailable SolidInvoice API without crashing', function (): void {
    $this->app->instance(InvoiceGateway::class, new class implements InvoiceGateway
    {
        public function listEligible(int $limit): array
        {
            throw new RuntimeException('SolidInvoice connection timed out');
        }

        public function get(string $invoiceId): ExternalInvoice
        {
            throw new RuntimeException('not reached');
        }

        public function markPaid(string $invoiceId, SettlementReceipt $receipt, string $idempotencyKey): void {}

        public function name(): string
        {
            return 'solidinvoice';
        }
    });

    $this->artisan('settlements:poll-solidinvoice')->assertExitCode(1);
    expect(Settlement::count())->toBe(0);
});

test('poller skips a confirmed settlement without adding a duplicate audit event', function (): void {
    $settlement = Settlement::create([
        'source' => 'solidinvoice',
        'source_invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
        'idempotency_key' => 'confirmed-poller-idempotency-key',
        'provider_idempotency_key' => '1b62427d-0b8d-4b19-9bf9-c91ac0991c95',
        'status' => SettlementStatus::CONFIRMED,
        'confirmed_at' => now(),
    ]);
    SettlementEvent::create([
        'settlement_id' => $settlement->id,
        'type' => SettlementEventType::SETTLEMENT_CONFIRMED,
        'payload' => [],
    ]);

    $this->artisan('settlements:poll-solidinvoice')->assertExitCode(0);

    expect(Settlement::query()->where('source_invoice_id', 'INV-001')->count())->toBe(1)
        ->and(SettlementEvent::query()->where('settlement_id', $settlement->id)->count())->toBe(1);
});
