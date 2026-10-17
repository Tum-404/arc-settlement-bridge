<?php

declare(strict_types=1);

use App\Domain\Settlement\Models\Recipient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('can list all recipients via api', function (): void {
    Recipient::create([
        'client_id' => 'client_test_1',
        'client_name' => 'Test Vendor',
        'crypto_address' => '0x1111111111111111111111111111111111111111',
        'network' => 'ARC-TESTNET',
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/recipients');

    $response->assertStatus(200)
        ->assertJsonFragment(['client_id' => 'client_test_1']);
});

test('can register a new recipient with valid 0x address', function (): void {
    $response = $this->postJson('/api/recipients', [
        'client_id' => 'client_supplier_99',
        'client_name' => 'Global Logistics Inc',
        'crypto_address' => '0x9999999999999999999999999999999999999999',
        'network' => 'ARC-TESTNET',
    ]);

    $response->assertStatus(201)
        ->assertJsonFragment(['client_id' => 'client_supplier_99', 'is_active' => true]);

    $this->assertDatabaseHas('recipients', [
        'client_id' => 'client_supplier_99',
        'crypto_address' => '0x9999999999999999999999999999999999999999',
    ]);
});

test('rejects registration with invalid crypto address', function (): void {
    $response = $this->postJson('/api/recipients', [
        'client_id' => 'client_bad',
        'crypto_address' => 'not-a-valid-hex-address',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['crypto_address']);
});

test('rejects assigning the same wallet to different clients regardless of address casing', function (): void {
    $this->postJson('/api/recipients', [
        'client_id' => 'wallet-owner-a',
        'crypto_address' => '0xAbCd000000000000000000000000000000000001',
    ])->assertCreated();

    $this->postJson('/api/recipients', [
        'client_id' => 'wallet-owner-b',
        'crypto_address' => '0xabcd000000000000000000000000000000000001',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['crypto_address']);
});

test('can identify a recipient by wallet address regardless of address casing', function (): void {
    $response = $this->getJson('/api/recipients/by-wallet/0x71c836643f37d110e49952042125585097486ef8');

    $response->assertOk()
        ->assertJsonPath('recipient.client_id', 'Acme Corporation');
});

test('can toggle recipient active status', function (): void {
    $recipient = Recipient::create([
        'client_id' => 'toggle_client',
        'crypto_address' => '0x2222222222222222222222222222222222222222',
        'network' => 'ARC-TESTNET',
        'is_active' => true,
    ]);

    $response = $this->patchJson("/api/recipients/{$recipient->id}/toggle");

    $response->assertStatus(200)
        ->assertJsonFragment(['is_active' => false]);

    expect($recipient->fresh()->is_active)->toBeFalse();

    // Toggle back
    $this->patchJson("/api/recipients/{$recipient->id}/toggle");
    expect($recipient->fresh()->is_active)->toBeTrue();
});

test('can delete a recipient', function (): void {
    $recipient = Recipient::create([
        'client_id' => 'delete_client',
        'crypto_address' => '0x3333333333333333333333333333333333333333',
        'network' => 'ARC-TESTNET',
        'is_active' => true,
    ]);

    $response = $this->deleteJson("/api/recipients/{$recipient->id}");

    $response->assertStatus(200);

    $this->assertDatabaseMissing('recipients', [
        'id' => $recipient->id,
    ]);
});

test('creating settlement for unregistered recipient automatically registers it as inactive to prevent user error', function (): void {
    // Delete any pre-seeded recipient for Acme Corporation
    Recipient::query()->delete();

    $response = $this->postJson('/api/settlements', [
        'source' => 'solidinvoice',
        'invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
    ]);

    $response->assertStatus(409)
        ->assertJsonFragment([
            'message' => 'Client [Acme Corporation] was not registered in the recipients table. It has now been automatically added as INACTIVE to prevent user error. Please review and activate it on the dashboard before settling.',
        ]);

    $this->assertDatabaseHas('recipients', [
        'client_id' => 'Acme Corporation',
        'crypto_address' => '0x71C836643F37D110e49952042125585097486eF8',
        'is_active' => false,
    ]);
});

test('activating auto-registered recipient allows settlement to proceed', function (): void {
    Recipient::query()->delete();

    // 1. Initial attempt fails and auto-registers as inactive
    $this->postJson('/api/settlements', [
        'source' => 'solidinvoice',
        'invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
    ])->assertStatus(409);

    $recipient = Recipient::where('client_id', 'Acme Corporation')->first();
    expect($recipient)->not->toBeNull();
    expect($recipient->is_active)->toBeFalse();

    // 2. User activates payee via dashboard toggle endpoint
    $toggleResponse = $this->patchJson("/api/recipients/{$recipient->id}/toggle");
    $toggleResponse->assertStatus(200);
    expect($recipient->fresh()->is_active)->toBeTrue();

    // 3. Retry settlement succeeds now that payee is active
    $settleResponse = $this->postJson('/api/settlements', [
        'source' => 'solidinvoice',
        'invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
    ]);

    $settleResponse->assertStatus(201)
        ->assertJsonFragment(['status' => 'submitted']);
});

test('rejects a request client_id that does not match the authoritative invoice client', function (): void {
    $response = $this->postJson('/api/settlements', [
        'source' => 'solidinvoice',
        'invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
        'client_id' => 'Globex Inc',
    ]);

    $response->assertStatus(409)
        ->assertJsonFragment([
            'message' => 'Settlement request does not match the source invoice client_id.',
        ]);

    $this->assertDatabaseCount('settlements', 0);
});

test('accepts a request client_id that matches the authoritative invoice client', function (): void {
    $response = $this->postJson('/api/settlements', [
        'source' => 'solidinvoice',
        'invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
        'client_id' => 'acme corporation',
    ]);

    $response->assertCreated()
        ->assertJsonFragment(['status' => 'submitted']);
});
