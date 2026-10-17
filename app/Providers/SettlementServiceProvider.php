<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\Contracts\SettlementProvider;
use App\Domain\Settlement\Services\IdempotencyKeyGenerator;
use App\Domain\Settlement\Services\SettlementStateMachine;
use App\Infrastructure\Arc\ArcSettlementProvider;
use App\Infrastructure\Arc\CircleClient;
use App\Infrastructure\Arc\CircleWebhookVerifier;
use App\Infrastructure\Providers\FakeSettlementProvider;
use App\Infrastructure\SolidInvoice\FakeInvoiceGateway;
use App\Infrastructure\SolidInvoice\SolidInvoiceGateway;
use App\Infrastructure\SolidInvoice\SolidInvoiceWebhookVerifier;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

final class SettlementServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(SettlementStateMachine::class);
        $this->app->singleton(IdempotencyKeyGenerator::class);

        $this->app->singleton(CircleWebhookVerifier::class, function (): CircleWebhookVerifier {
            return new CircleWebhookVerifier(
                webhookSecret: (string) config('settlement.arc.webhook_secret', ''),
                apiKey: (string) config('settlement.arc.circle_api_key', ''),
                baseUrl: (string) config('settlement.arc.base_url', 'https://api.circle.com'),
            );
        });

        $this->app->singleton(SolidInvoiceWebhookVerifier::class, function (): SolidInvoiceWebhookVerifier {
            return new SolidInvoiceWebhookVerifier(
                webhookSecret: (string) config('settlement.solidinvoice.webhook_secret', ''),
                maxAgeSeconds: (int) config('settlement.solidinvoice.webhook_max_age_seconds', 300),
            );
        });

        $this->app->singleton(CircleClient::class, function (Application $app): CircleClient {
            return new CircleClient(
                http: $app->make(HttpFactory::class),
                apiKey: (string) config('settlement.arc.circle_api_key', ''),
                baseUrl: (string) config('settlement.arc.base_url', 'https://api.circle.com'),
                blockchain: (string) config('settlement.arc.blockchain', 'ARC-TESTNET'),
                entitySecret: (string) config('settlement.arc.circle_entity_secret', ''),
            );
        });

        $this->app->singleton(SettlementProvider::class, function (Application $app): SettlementProvider {
            $driver = config('settlement.provider', 'fake');

            if ($driver === 'arc') {
                return new ArcSettlementProvider(
                    circle: $app->make(CircleClient::class),
                    walletId: (string) config('settlement.arc.wallet_id', ''),
                    tokenAddress: config('settlement.arc.usdc_contract_address') ?: null,
                    tokenId: config('settlement.arc.token_id') ?: null,
                );
            }

            return $app->make(FakeSettlementProvider::class);
        });

        $this->app->singleton(InvoiceGateway::class, function (Application $app): InvoiceGateway {
            $driver = config('settlement.invoice_gateway', 'fake');

            if ($driver === 'solidinvoice') {
                return new SolidInvoiceGateway(
                    http: $app->make(HttpFactory::class),
                    baseUrl: (string) config('settlement.solidinvoice.base_url', 'http://localhost:8080'),
                    apiToken: (string) config('settlement.solidinvoice.api_token', ''),
                );
            }

            return $app->make(FakeInvoiceGateway::class);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void {}
}
