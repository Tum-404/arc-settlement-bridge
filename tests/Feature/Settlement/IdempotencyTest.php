<?php

declare(strict_types=1);

use App\Application\Settlement\Commands\CreateSettlement;
use App\Domain\Settlement\Contracts\SettlementProvider;
use App\Domain\Settlement\DTOs\CreateSettlementCommand;
use App\Domain\Settlement\DTOs\SubmissionResult;
use App\Domain\Settlement\Enums\SettlementEventType;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementEvent;
use Mockery\MockInterface;

beforeEach(function (): void {
    // Reset test state
});

test('duplicate request creates exactly one settlement and returns existing resource', function (): void {
    $payload = [
        'source' => 'solidinvoice',
        'invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
    ];

    // First request
    $response1 = $this->postJson('/api/settlements', $payload);
    $response1->assertStatus(201);
    $data1 = $response1->json();

    expect($data1['duplicate'])->toBeFalse()
        ->and($data1['status'])->toBe('submitted')
        ->and($data1['idempotency_key'])->not->toBeEmpty();

    // Second identical request
    $response2 = $this->postJson('/api/settlements', $payload);
    $response2->assertStatus(200);
    $data2 = $response2->json();

    expect($data2['duplicate'])->toBeTrue()
        ->and($data2['id'])->toBe($data1['id'])
        ->and($data2['idempotency_key'])->toBe($data1['idempotency_key']);

    // Database check: exactly 1 settlement row
    expect(Settlement::count())->toBe(1);

    // Audit trail check: records SETTLEMENT_CREATED, PAYMENT_SUBMITTED, and DUPLICATE_REQUEST_RECEIVED
    $events = SettlementEvent::where('settlement_id', $data1['id'])->get();
    expect($events->pluck('type')->map->value->toArray())->toContain(
        SettlementEventType::SETTLEMENT_CREATED->value,
        SettlementEventType::PAYMENT_SUBMITTED->value,
        SettlementEventType::DUPLICATE_REQUEST_RECEIVED->value,
    );
});

test('ten identical payment requests result in exactly one database settlement', function (): void {
    $payload = [
        'source' => 'solidinvoice',
        'invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
    ];

    for ($i = 0; $i < 10; $i++) {
        $response = $this->postJson('/api/settlements', $payload);
        if ($i === 0) {
            $response->assertStatus(201);
            expect($response->json('duplicate'))->toBeFalse();
        } else {
            $response->assertStatus(200);
            expect($response->json('duplicate'))->toBeTrue();
        }
    }

    expect(Settlement::count())->toBe(1);

    $duplicateEvents = SettlementEvent::where('type', SettlementEventType::DUPLICATE_REQUEST_RECEIVED)->count();
    expect($duplicateEvents)->toBe(9);
});

test('amount normalization guarantees identical key across equivalent decimal string formats', function (): void {
    $amounts = ['250.5', '250.50', '250.500', '250.500000', ' 250.500000 '];

    $settlementIds = [];

    foreach ($amounts as $amount) {
        $response = $this->postJson('/api/settlements', [
            'source' => 'solidinvoice',
            'invoice_id' => 'INV-002',
            'recipient' => '0x3A2154483B8e72D714C808E1Dea0fDb00f13B9B9',
            'amount' => $amount,
            'currency' => 'USDC',
        ]);

        $settlementIds[] = $response->json('id');
    }

    // All must resolve to the identical settlement
    expect(array_unique($settlementIds))->toHaveCount(1)
        ->and(Settlement::where('source_invoice_id', 'INV-002')->count())->toBe(1);
});

test('concurrent duplicate requests submit to provider only once', function (): void {
    // Mock the provider to ensure submit() is invoked strictly once
    $providerMock = $this->mock(SettlementProvider::class, function (MockInterface $mock): void {
        $mock->shouldReceive('name')->andReturn('mock_provider');
        $mock->shouldReceive('submit')
            ->once()
            ->andReturn(new SubmissionResult(
                providerTransactionId: 'mock-tx-123',
                txHash: '0xmockhash',
                provider: 'mock_provider',
            ));
    });

    $createSettlement = app(CreateSettlement::class);

    $command = new CreateSettlementCommand(
        source: 'solidinvoice',
        invoiceId: 'INV-RACE-01',
        recipient: '0x71C836643F37D110e49952042125585097486eF8',
        amount: '100.000000',
        currency: 'USDC',
    );

    // Run first call
    $result1 = $createSettlement->execute($command);
    expect($result1['is_duplicate'])->toBeFalse();

    // Run subsequent calls
    $result2 = $createSettlement->execute($command);
    expect($result2['is_duplicate'])->toBeTrue();

    $result3 = $createSettlement->execute($command);
    expect($result3['is_duplicate'])->toBeTrue();

    expect(Settlement::where('source_invoice_id', 'INV-RACE-01')->count())->toBe(1);
});
