<?php

declare(strict_types=1);

use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\Contracts\SettlementProvider;
use App\Domain\Settlement\DTOs\SubmissionResult;
use App\Domain\Settlement\Enums\SettlementEventType;
use App\Domain\Settlement\Enums\SettlementStatus;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementEvent;
use App\Infrastructure\SolidInvoice\FakeInvoiceGateway;
use Mockery\MockInterface;

beforeEach(function (): void {
    FakeInvoiceGateway::reset();
});

test('full end-to-end lifecycle: 10 identical requests -> 1 database row -> 1 provider transfer -> webhook confirmed -> 1 invoice reconciliation', function (): void {
    // 1. Mock the SettlementProvider to verify exactly ONE submission occurs
    $providerMock = $this->mock(SettlementProvider::class, function (MockInterface $mock): void {
        $mock->shouldReceive('name')->andReturn('arc');
        $mock->shouldReceive('submit')
            ->once()
            ->andReturn(new SubmissionResult(
                providerTransactionId: 'circle-txn-e2e-001',
                txHash: '0x99fa1234567890abcdef1234567890abcdef1234567890abcdef1234567890ab',
                provider: 'arc',
            ));
    });

    /** @var FakeInvoiceGateway $gateway */
    $gateway = app(InvoiceGateway::class);
    expect($gateway->getInvoiceStatus('INV-001'))->toBe('unpaid');

    $payload = [
        'source' => 'solidinvoice',
        'invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
    ];

    // 2. Fire 10 identical payment requests
    $firstResponse = null;
    for ($i = 0; $i < 10; $i++) {
        $response = $this->postJson('/api/settlements', $payload);
        if ($i === 0) {
            $response->assertStatus(201);
            $firstResponse = $response->json();
            expect($firstResponse['duplicate'])->toBeFalse()
                ->and($firstResponse['status'])->toBe('submitted')
                ->and($firstResponse['provider_transaction_id'])->toBe('circle-txn-e2e-001');
        } else {
            $response->assertStatus(200);
            expect($response->json('duplicate'))->toBeTrue()
                ->and($response->json('id'))->toBe($firstResponse['id']);
        }
    }

    // Invariant 1: Exactly 1 database settlement row exists
    expect(Settlement::count())->toBe(1);

    // Invariant 2: Invoice remains UNPAID while SUBMITTED (SUBMITTED != PAID)
    expect($gateway->getInvoiceStatus('INV-001'))->toBe('unpaid');

    // 3. Circle Arc webhook arrives confirming transaction
    $webhookPayload = [
        'eventId' => 'evt_e2e_confirm_999',
        'data' => [
            'transaction' => [
                'id' => 'circle-txn-e2e-001',
                'state' => 'CONFIRMED',
                'txHash' => '0x99fa1234567890abcdef1234567890abcdef1234567890abcdef1234567890ab',
                'destinationAddress' => '0x71C836643F37D110e49952042125585097486eF8',
                'amounts' => ['100.000000'],
                'currency' => 'USDC',
            ],
        ],
    ];

    $webhookResponse = $this->postJson('/api/webhooks/arc', $webhookPayload);
    $webhookResponse->assertStatus(200);

    // 4. Verify post-confirmation invariants
    $settlement = Settlement::first();
    expect($settlement->status)->toBe(SettlementStatus::CONFIRMED)
        ->and($settlement->confirmed_at)->not->toBeNull();

    // Invariant 3: SolidInvoice is marked PAID only after confirmation
    expect($gateway->getInvoiceStatus('INV-001'))->toBe('paid');

    $receipt = $gateway->getReceipt('INV-001');
    expect($receipt)->not->toBeNull()
        ->and($receipt->txHash)->toBe('0x99fa1234567890abcdef1234567890abcdef1234567890abcdef1234567890ab');

    // 5. Verify the entire chronological audit trail
    $events = SettlementEvent::where('settlement_id', $settlement->id)->orderBy('id', 'asc')->get();

    $types = $events->pluck('type')->map->value->toArray();

    expect($types[0])->toBe(SettlementEventType::SETTLEMENT_CREATED->value);
    expect($types[1])->toBe(SettlementEventType::PAYMENT_SUBMITTED->value);

    // 9 duplicate blocked events
    $duplicateEventsCount = count(array_filter($types, fn ($t) => $t === SettlementEventType::DUPLICATE_REQUEST_RECEIVED->value));
    expect($duplicateEventsCount)->toBe(9);

    // Last events are confirmation and reconciliation
    expect(end($types))->toBe(SettlementEventType::INVOICE_RECONCILED->value);
    expect($types[count($types) - 2])->toBe(SettlementEventType::RECONCILIATION_QUEUED->value);
    expect($types[count($types) - 3])->toBe(SettlementEventType::SETTLEMENT_CONFIRMED->value);
});
