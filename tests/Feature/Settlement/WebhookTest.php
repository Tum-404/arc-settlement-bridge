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

test('replayed webhook delivery is safe and idempotent', function (): void {
    $settlement = Settlement::create([
        'source' => 'solidinvoice',
        'source_invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
        'idempotency_key' => 'webhook-test-key-1',
        'status' => SettlementStatus::SUBMITTED,
        'provider' => 'arc',
        'provider_transaction_id' => 'circle-tx-999',
        'submitted_at' => now(),
    ]);

    $webhookPayload = [
        'eventId' => 'evt_circle_12345',
        'notificationType' => 'transactions',
        'data' => [
            'transaction' => [
                'id' => 'circle-tx-999',
                'state' => 'CONFIRMED',
                'txHash' => '0x9999888877776666555544443333222211110000',
                'destinationAddress' => '0x71C836643F37D110e49952042125585097486eF8',
                'amounts' => ['100.000000'],
                'currency' => 'USDC',
            ],
        ],
    ];

    // First webhook delivery
    $response1 = $this->postJson('/api/webhooks/arc', $webhookPayload);
    $response1->assertStatus(200)
        ->assertJson(['status' => 'processed']);

    $settlement->refresh();
    expect($settlement->status)->toBe(SettlementStatus::CONFIRMED)
        ->and($settlement->tx_hash)->toBe('0x9999888877776666555544443333222211110000')
        ->and($settlement->confirmed_at)->not->toBeNull();

    /** @var FakeInvoiceGateway $gateway */
    $gateway = app(InvoiceGateway::class);
    expect($gateway->getInvoiceStatus('INV-001'))->toBe('paid');

    // Second webhook delivery with identical eventId (replay)
    $response2 = $this->postJson('/api/webhooks/arc', $webhookPayload);
    $response2->assertStatus(200)
        ->assertJson(['status' => 'acknowledged', 'duplicate' => true]);

    // Third webhook delivery with different eventId but already confirmed transaction
    $webhookPayload3 = $webhookPayload;
    $webhookPayload3['eventId'] = 'evt_circle_67890';
    $response3 = $this->postJson('/api/webhooks/arc', $webhookPayload3);
    $response3->assertStatus(200);

    // Verify invoice was reconciled exactly once
    $reconcileEvents = SettlementEvent::where('settlement_id', $settlement->id)
        ->where('type', SettlementEventType::INVOICE_RECONCILED)
        ->count();

    expect($reconcileEvents)->toBe(1);
});

test('mismatched webhook amount rejects confirmation and prevents reconciliation', function (): void {
    $settlement = Settlement::create([
        'source' => 'solidinvoice',
        'source_invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
        'idempotency_key' => 'webhook-test-key-2',
        'status' => SettlementStatus::SUBMITTED,
        'provider' => 'arc',
        'provider_transaction_id' => 'circle-tx-amount-mismatch',
        'submitted_at' => now(),
    ]);

    $webhookPayload = [
        'eventId' => 'evt_mismatch_amount',
        'data' => [
            'transaction' => [
                'id' => 'circle-tx-amount-mismatch',
                'state' => 'CONFIRMED',
                'txHash' => '0x1111222233334444',
                'destinationAddress' => '0x71C836643F37D110e49952042125585097486eF8',
                'amounts' => ['50.000000'], // Expected 100.000000
                'currency' => 'USDC',
            ],
        ],
    ];

    $response = $this->postJson('/api/webhooks/arc', $webhookPayload);
    $response->assertStatus(422)
        ->assertJsonFragment(['status' => 'error']);

    $settlement->refresh();
    expect($settlement->status)->toBe(SettlementStatus::SUBMITTED);

    /** @var FakeInvoiceGateway $gateway */
    $gateway = app(InvoiceGateway::class);
    expect($gateway->getInvoiceStatus('INV-001'))->toBe('unpaid');
});

test('mismatched webhook recipient rejects confirmation and prevents reconciliation', function (): void {
    $settlement = Settlement::create([
        'source' => 'solidinvoice',
        'source_invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
        'idempotency_key' => 'webhook-test-key-3',
        'status' => SettlementStatus::SUBMITTED,
        'provider' => 'arc',
        'provider_transaction_id' => 'circle-tx-recipient-mismatch',
        'submitted_at' => now(),
    ]);

    $webhookPayload = [
        'eventId' => 'evt_mismatch_recipient',
        'data' => [
            'transaction' => [
                'id' => 'circle-tx-recipient-mismatch',
                'state' => 'CONFIRMED',
                'destinationAddress' => '0xWRONG_ATTACKER_ADDRESS',
                'amounts' => ['100.000000'],
                'currency' => 'USDC',
            ],
        ],
    ];

    $response = $this->postJson('/api/webhooks/arc', $webhookPayload);
    $response->assertStatus(422);

    $settlement->refresh();
    expect($settlement->status)->toBe(SettlementStatus::SUBMITTED);

    /** @var FakeInvoiceGateway $gateway */
    $gateway = app(InvoiceGateway::class);
    expect($gateway->getInvoiceStatus('INV-001'))->toBe('unpaid');
});
