<?php

declare(strict_types=1);

use App\Domain\Settlement\Models\ProviderWebhookEvent;
use App\Domain\Settlement\Models\Settlement;

beforeEach(function (): void {
    config()->set('settlement.solidinvoice.webhook_secret', 'solidinvoice-test-secret');
    config()->set('settlement.solidinvoice.webhook_max_age_seconds', 300);
});

function signedSolidInvoiceCallback(array $payload, string $secret = 'solidinvoice-test-secret'): array
{
    $timestamp = now()->toIso8601String();
    $content = json_encode($payload, JSON_THROW_ON_ERROR);

    return [
        'content' => $content,
        'server' => [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SOLIDINVOICE_TIMESTAMP' => $timestamp,
            'HTTP_X_SOLIDINVOICE_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$content, $secret),
        ],
    ];
}

test('a signed SolidInvoice pending event creates one settlement', function (): void {
    $callback = signedSolidInvoiceCallback([
        'event_id' => 'solidinvoice-event-001',
        'event' => 'invoice.pending',
        'data' => ['invoice_id' => 'INV-001'],
    ]);

    $response = $this->call('POST', '/api/webhooks/solidinvoice', [], [], [], $callback['server'], $callback['content']);

    $response->assertCreated()
        ->assertJson(['status' => 'processed', 'event_id' => 'solidinvoice-event-001', 'duplicate' => false]);
    expect(Settlement::count())->toBe(1)
        ->and(ProviderWebhookEvent::where('provider_event_id', 'solidinvoice-event-001')->value('processing_status'))->toBe('processed');
});

test('replayed SolidInvoice pending event is acknowledged without another transfer', function (): void {
    $payload = [
        'event_id' => 'solidinvoice-event-replay',
        'event' => 'invoice.pending',
        'data' => ['invoice_id' => 'INV-001'],
    ];

    $first = signedSolidInvoiceCallback($payload);
    $this->call('POST', '/api/webhooks/solidinvoice', [], [], [], $first['server'], $first['content'])->assertCreated();

    $second = signedSolidInvoiceCallback($payload);
    $this->call('POST', '/api/webhooks/solidinvoice', [], [], [], $second['server'], $second['content'])
        ->assertOk()
        ->assertJson(['status' => 'acknowledged', 'duplicate' => true]);

    expect(Settlement::count())->toBe(1);
});

test('unsigned SolidInvoice callback is rejected', function (): void {
    $this->postJson('/api/webhooks/solidinvoice', [
        'event_id' => 'solidinvoice-event-unsigned',
        'event' => 'invoice.pending',
        'data' => ['invoice_id' => 'INV-001'],
    ])->assertUnauthorized();

    expect(Settlement::count())->toBe(0);
});

test('SolidInvoice callback accepts only invoice pending events', function (): void {
    $callback = signedSolidInvoiceCallback([
        'event_id' => 'solidinvoice-event-paid',
        'event' => 'invoice.paid',
        'data' => ['invoice_id' => 'INV-001'],
    ]);

    $this->call('POST', '/api/webhooks/solidinvoice', [], [], [], $callback['server'], $callback['content'])
        ->assertUnprocessable();

    expect(Settlement::count())->toBe(0);
});
