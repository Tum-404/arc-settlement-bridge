<?php

declare(strict_types=1);

use App\Application\Settlement\Commands\CreateSettlement;
use App\Application\Settlement\Commands\ReconcileSettlement;
use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\DTOs\CreateSettlementCommand;
use App\Domain\Settlement\Enums\SettlementEventType;
use App\Domain\Settlement\Enums\SettlementStatus;
use App\Domain\Settlement\Exceptions\InvalidStateTransitionException;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementEvent;
use App\Infrastructure\Providers\FakeSettlementProvider;
use App\Infrastructure\SolidInvoice\FakeInvoiceGateway;

beforeEach(function (): void {
    FakeSettlementProvider::reset();
    FakeInvoiceGateway::reset();
});

test('submitted settlement does not mark invoice paid (SUBMITTED != PAID)', function (): void {
    $response = $this->postJson('/api/settlements', [
        'source' => 'solidinvoice',
        'invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
    ]);

    $response->assertStatus(201);
    expect($response->json('status'))->toBe('submitted');

    /** @var InvoiceGateway $gateway */
    $gateway = app(InvoiceGateway::class);
    $invoice = $gateway->get('INV-001');

    // The core invariant: invoice must remain unpaid upon submission
    expect($invoice->status)->toBe('unpaid');
});

test('reconciliation cannot occur on unconfirmed settlement', function (): void {
    $settlement = Settlement::create([
        'source' => 'solidinvoice',
        'source_invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
        'idempotency_key' => 'test-idempotency-key',
        'status' => SettlementStatus::SUBMITTED,
        'provider' => 'fake',
        'provider_transaction_id' => 'fake-tx-123',
    ]);

    $reconciler = app(ReconcileSettlement::class);

    expect(fn () => $reconciler->execute($settlement))
        ->toThrow(InvalidStateTransitionException::class, "Cannot reconcile settlement in 'submitted' state");

    /** @var InvoiceGateway $gateway */
    $gateway = app(InvoiceGateway::class);
    expect($gateway->get('INV-001')->status)->toBe('unpaid');
});

test('failed settlement records failure details and leaves invoice unpaid', function (): void {
    FakeSettlementProvider::simulateFailure(true);

    $command = new CreateSettlementCommand(
        source: 'solidinvoice',
        invoiceId: 'INV-001',
        recipient: '0x71C836643F37D110e49952042125585097486eF8',
        amount: '100.000000',
        currency: 'USDC',
    );

    $createSettlement = app(CreateSettlement::class);
    $result = $createSettlement->execute($command);
    $settlement = $result['settlement'];

    expect($settlement->status)->toBe(SettlementStatus::FAILED)
        ->and($settlement->failure_code)->toBe('SUBMISSION_FAILED')
        ->and($settlement->failure_reason)->toContain('Simulated provider transfer failure')
        ->and($settlement->failed_at)->not->toBeNull();

    // Verify SETTLEMENT_FAILED event
    $failedEvent = SettlementEvent::where('settlement_id', $settlement->id)
        ->where('type', SettlementEventType::SETTLEMENT_FAILED)
        ->first();

    expect($failedEvent)->not->toBeNull();

    /** @var InvoiceGateway $gateway */
    $gateway = app(InvoiceGateway::class);
    expect($gateway->get('INV-001')->status)->toBe('unpaid');
});
