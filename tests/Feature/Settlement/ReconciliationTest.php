<?php

declare(strict_types=1);

use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\Enums\SettlementEventType;
use App\Domain\Settlement\Enums\SettlementStatus;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementEvent;
use App\Infrastructure\SolidInvoice\FakeInvoiceGateway;

beforeEach(function (): void {
    FakeInvoiceGateway::reset();
});

test('confirmed settlement marks invoice paid with verifiable receipt evidence', function (): void {
    $createResponse = $this->postJson('/api/settlements', [
        'source' => 'solidinvoice',
        'invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
    ]);

    $createResponse->assertStatus(201);
    $settlementId = $createResponse->json('id');
    $providerTxId = $createResponse->json('provider_transaction_id');
    $txHash = $createResponse->json('tx_hash');

    /** @var FakeInvoiceGateway $gateway */
    $gateway = app(InvoiceGateway::class);
    expect($gateway->getInvoiceStatus('INV-001'))->toBe('unpaid');

    // Confirm settlement via simulation endpoint
    $confirmResponse = $this->postJson("/api/settlements/{$settlementId}/simulate-confirm");
    $confirmResponse->assertStatus(200);

    // Verify settlement status
    $settlement = Settlement::find($settlementId);
    expect($settlement->status)->toBe(SettlementStatus::CONFIRMED)
        ->and($settlement->confirmed_at)->not->toBeNull();

    // Verify invoice marked paid
    expect($gateway->getInvoiceStatus('INV-001'))->toBe('paid');

    // Verify external receipt evidence
    $receipt = $gateway->getReceipt('INV-001');
    expect($receipt)->not->toBeNull()
        ->and($receipt->txHash)->toBe($txHash)
        ->and($receipt->confirmedAt)->not->toBeNull();

    // Verify INVOICE_RECONCILED event
    $reconciledEvent = SettlementEvent::where('settlement_id', $settlementId)
        ->where('type', SettlementEventType::INVOICE_RECONCILED)
        ->first();

    expect($reconciledEvent)->not->toBeNull()
        ->and($reconciledEvent->payload['source_invoice_id'])->toBe('INV-001')
        ->and($reconciledEvent->payload['tx_hash'])->toBe($txHash);
});
