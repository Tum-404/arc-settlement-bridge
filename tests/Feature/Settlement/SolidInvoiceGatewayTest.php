<?php

declare(strict_types=1);

use App\Infrastructure\SolidInvoice\SolidInvoiceGateway;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('resolves a client IRI even when the invoice already supplies a recipient address', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/api/clients/client-01')) {
            return Http::response([
                'id' => 'client-01',
                'name' => 'Acme Corporation',
                'customFields' => ['crypto_address' => '0x1111111111111111111111111111111111111111'],
            ]);
        }

        return Http::response([[
            'id' => 'invoice-01',
            'invoiceId' => 'INV-001',
            'status' => 'pending',
            'total' => '100.000000',
            'currency' => 'USDC',
            'customFields' => ['crypto_address' => '0x2222222222222222222222222222222222222222'],
            'client' => '/api/clients/client-01',
        ]]);
    });

    $gateway = new SolidInvoiceGateway(
        http: app(HttpFactory::class),
        baseUrl: 'http://solidinvoice.test',
        apiToken: 'test-token',
    );

    $invoice = $gateway->listEligible(10)[0];

    expect($invoice->recipient)->toBe('0x2222222222222222222222222222222222222222')
        ->and($invoice->client)->toBe('Acme Corporation');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/clients/client-01'));
});
