<?php

declare(strict_types=1);

use App\Domain\Settlement\Models\Settlement;
use App\Infrastructure\Arc\ArcSettlementProvider;
use App\Infrastructure\Arc\CircleClient;
use Illuminate\Http\Client\Factory as HttpFactory;

function arcConfigurationSettlement(): Settlement
{
    return new Settlement([
        'recipient' => '0x1111111111111111111111111111111111111111',
        'amount' => '1.000000',
        'idempotency_key' => 'test-idempotency-key',
        'provider_idempotency_key' => 'test-provider-idempotency-key',
    ]);
}

test('arc provider rejects missing Circle credentials before making a transfer request', function (): void {
    $provider = new ArcSettlementProvider(
        circle: new CircleClient(app(HttpFactory::class), ''),
        walletId: 'wallet-id',
        tokenId: 'usdc-token-id',
    );

    expect(fn () => $provider->submit(arcConfigurationSettlement()))
        ->toThrow(RuntimeException::class, 'CIRCLE_API_KEY must be configured');
});

test('arc provider requires a wallet and explicit USDC token configuration', function (): void {
    $circle = new CircleClient(
        http: app(HttpFactory::class),
        apiKey: 'test-api-key',
        entitySecret: str_repeat('a', 64),
    );

    $missingWallet = new ArcSettlementProvider($circle, walletId: '', tokenId: 'usdc-token-id');
    expect(fn () => $missingWallet->submit(arcConfigurationSettlement()))
        ->toThrow(RuntimeException::class, 'ARC_WALLET_ID must be configured');

    $missingToken = new ArcSettlementProvider($circle, walletId: 'wallet-id');
    expect(fn () => $missingToken->submit(arcConfigurationSettlement()))
        ->toThrow(RuntimeException::class, 'ARC_USDC_CONTRACT_ADDRESS or ARC_TOKEN_ID');
});

test('demo routes are unavailable unless demo mode is explicitly enabled', function (): void {
    config()->set('settlement.demo_mode', false);

    $this->postJson('/demo/reset')->assertNotFound();
});
