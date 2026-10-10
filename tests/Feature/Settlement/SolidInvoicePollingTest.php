<?php

declare(strict_types=1);

use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\DTOs\ExternalInvoice;
use App\Domain\Settlement\DTOs\SettlementReceipt;
use App\Domain\Settlement\Models\Settlement;
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
