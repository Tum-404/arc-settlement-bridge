<?php

declare(strict_types=1);

use App\Application\Settlement\Commands\ConfirmSettlement;
use App\Domain\Settlement\Contracts\SettlementProvider;
use App\Domain\Settlement\Enums\SettlementEventType;
use App\Domain\Settlement\Enums\SettlementStatus;
use App\Domain\Settlement\Exceptions\SettlementVerificationException;
use App\Domain\Settlement\Models\Settlement;
use Illuminate\Http\Client\ConnectionException;

test('a different payment coordinate for an existing invoice is rejected and audited', function (): void {
    $payload = [
        'source' => 'solidinvoice',
        'invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
    ];
    $this->postJson('/api/settlements', $payload)->assertCreated();

    $this->postJson('/api/settlements', [...$payload, 'amount' => '99.000000'])
        ->assertConflict();

    expect(Settlement::count())->toBe(1)
        ->and(Settlement::firstOrFail()->events()->where('type', SettlementEventType::SETTLEMENT_REQUEST_REJECTED)->count())->toBe(1);
});

test('incomplete settlement evidence cannot confirm a transaction', function (): void {
    $settlement = Settlement::create([
        'source' => 'solidinvoice', 'source_invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8', 'amount' => '100.000000',
        'currency' => 'USDC', 'idempotency_key' => 'incomplete-evidence',
        'status' => SettlementStatus::SUBMITTED, 'provider_transaction_id' => 'incomplete-tx',
    ]);

    expect(fn () => app(ConfirmSettlement::class)->execute('incomplete-tx', ['recipient' => $settlement->recipient]))
        ->toThrow(SettlementVerificationException::class);
    expect($settlement->fresh()->status)->toBe(SettlementStatus::SUBMITTED);
});

test('unsigned webhook callbacks are rejected when local unsigned mode is disabled', function (): void {
    config()->set('settlement.arc.allow_unsigned_webhooks', false);
    $this->postJson('/api/webhooks/arc', ['eventId' => 'unsigned'])->assertUnauthorized();
});

test('a provider connection failure remains recoverable instead of being marked failed', function (): void {
    $this->mock(SettlementProvider::class, function ($mock): void {
        $mock->shouldReceive('submit')->once()->andThrow(new ConnectionException('timeout'));
        $mock->shouldReceive('name')->andReturn('arc');
    });

    $response = $this->postJson('/api/settlements', [
        'source' => 'solidinvoice', 'invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000', 'currency' => 'USDC',
    ]);

    $response->assertCreated()->assertJsonPath('status', 'submission_unknown');
    expect(Settlement::firstOrFail()->failed_at)->toBeNull();
});
