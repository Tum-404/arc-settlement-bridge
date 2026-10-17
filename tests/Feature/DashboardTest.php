<?php

declare(strict_types=1);

use App\Domain\Settlement\Enums\SettlementEventType;
use App\Domain\Settlement\Enums\SettlementStatus;
use App\Domain\Settlement\Models\ProviderWebhookEvent;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementEvent;
use Inertia\Testing\AssertableInertia as Assert;

test('dashboard page renders successfully with invoices and settlements props', function (): void {
    $response = $this->get('/');

    $response->assertStatus(200);

    $response->assertInertia(fn (Assert $page) => $page
        ->component('SettlementDashboard')
        ->has('invoices', 2)
        ->has('settlements')
        ->has('recipients')
        ->has('webhookEvents')
        ->has('domainStats')
        ->where('provider', 'fake')
        ->where('invoiceGateway', 'fake')
    );
});

test('demo reset endpoint clears data safely', function (): void {
    $settlement = Settlement::create([
        'source' => 'solidinvoice',
        'source_invoice_id' => 'RESET-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
        'idempotency_key' => 'reset-idempotency-key',
        'provider_idempotency_key' => '5e7a7568-a551-4e5c-9ca6-2149221bd270',
        'status' => SettlementStatus::SUBMITTED,
    ]);
    SettlementEvent::create([
        'settlement_id' => $settlement->id,
        'type' => SettlementEventType::PAYMENT_SUBMITTED,
        'payload' => [],
    ]);
    ProviderWebhookEvent::create([
        'provider' => 'arc',
        'provider_event_id' => 'reset-event-001',
        'payload' => [],
    ]);

    $response = $this->postJson('/demo/reset');

    $response->assertStatus(200)
        ->assertJson(['message' => 'Demo data reset successfully.']);

    $this->assertDatabaseCount('settlements', 0);
    $this->assertDatabaseCount('settlement_events', 0);
    $this->assertDatabaseCount('provider_webhook_events', 0);
});
