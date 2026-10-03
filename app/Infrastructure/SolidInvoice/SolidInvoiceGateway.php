<?php

declare(strict_types=1);

namespace App\Infrastructure\SolidInvoice;

use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\DTOs\ExternalInvoice;
use App\Domain\Settlement\DTOs\SettlementReceipt;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

final class SolidInvoiceGateway implements InvoiceGateway
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $apiToken,
    ) {}

    public function get(string $invoiceId): ExternalInvoice
    {
        $response = $this->http
            ->baseUrl($this->baseUrl)
            ->withToken($this->apiToken)
            ->acceptJson()
            ->get("/api/invoices/{$invoiceId}");

        if (! $response->successful()) {
            throw new RuntimeException("Failed to fetch invoice {$invoiceId} from SolidInvoice: ".$response->body());
        }

        $data = $response->json();

        return new ExternalInvoice(
            id: (string) ($data['id'] ?? $invoiceId),
            invoiceNumber: (string) ($data['invoice_id'] ?? $invoiceId),
            recipient: (string) ($data['custom_fields']['crypto_address'] ?? $data['client']['custom_fields']['crypto_address'] ?? ''),
            amount: (string) ($data['total'] ?? '0.000000'),
            currency: (string) ($data['currency'] ?? 'USDC'),
            status: (string) ($data['status'] ?? 'unknown'),
            client: (string) ($data['client']['name'] ?? null),
        );
    }

    public function markPaid(string $invoiceId, SettlementReceipt $receipt, string $idempotencyKey): void
    {
        // A prior successful Bridge reconciliation is safe to acknowledge without
        // issuing a second accounting payment. Production installations should also
        // configure SolidInvoice to retain the Bridge reference below.
        if (strtolower($this->get($invoiceId)->status) === 'paid') {
            return;
        }

        // Route through SolidInvoice's supported payment write path with external witness evidence
        $response = $this->http
            ->baseUrl($this->baseUrl)
            ->withToken($this->apiToken)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->acceptJson()
            ->post("/api/invoices/{$invoiceId}/payments", [
                'method' => 'arc_settlement',
                'completed' => $receipt->confirmedAt->format('Y-m-d H:i:s'),
                'reference' => $idempotencyKey,
                'description' => "Settled via Arc Settlement Bridge. TX: {$receipt->txHash}",
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Failed to record payment in SolidInvoice for invoice {$invoiceId}: ".$response->body());
        }
    }

    public function name(): string
    {
        return 'solidinvoice';
    }
}
