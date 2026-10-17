<?php

declare(strict_types=1);

namespace App\Infrastructure\SolidInvoice;

use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\DTOs\ExternalInvoice;
use App\Domain\Settlement\DTOs\SettlementReceipt;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
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
        $response = $this->request()->get("/api/invoices/{$invoiceId}");

        if ($response->successful()) {
            return $this->toExternalInvoice($response->json(), $invoiceId);
        }

        try {
            $eligible = $this->listEligible(50);
            foreach ($eligible as $inv) {
                if ($inv->id === $invoiceId || $inv->invoiceNumber === $invoiceId || "INV-{$inv->invoiceNumber}" === $invoiceId) {
                    return $inv;
                }
            }
        } catch (\Throwable) {
            // Fall through to original exception
        }

        throw new RuntimeException("Failed to fetch invoice {$invoiceId} from SolidInvoice: ".$response->body());
    }

    /** @return list<ExternalInvoice> */
    public function listEligible(int $limit): array
    {
        $invoices = [];

        // SolidInvoice API Platform SearchFilter uses exact match on status.
        // Query pending and overdue invoices individually.
        foreach (['pending', 'overdue'] as $status) {
            $response = $this->request()->get('/api/invoices', [
                'status' => $status,
                'itemsPerPage' => $limit,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Failed to list payable invoices from SolidInvoice: '.$response->body());
            }

            $payload = $response->json();
            $rows = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
            if (! is_array($rows)) {
                throw new RuntimeException('SolidInvoice returned an invalid invoice list.');
            }

            foreach ($rows as $row) {
                if (! is_array($row) || ! in_array(strtolower((string) ($row['status'] ?? '')), ['pending', 'overdue', 'unpaid'], true)) {
                    continue;
                }
                $invoices[] = $this->toExternalInvoice($row, (string) ($row['id'] ?? ''));
                if (count($invoices) >= $limit) {
                    return $invoices;
                }
            }
        }

        return $invoices;
    }

    private function request(): PendingRequest
    {
        $headers = [
            'X-API-TOKEN' => $this->apiToken,
        ];

        $hostHeader = (string) config('settlement.solidinvoice.host_header', 'localhost');
        if ($hostHeader !== '') {
            $headers['Host'] = $hostHeader;
        }

        return $this->http
            ->baseUrl($this->baseUrl)
            ->withHeaders($headers)
            ->acceptJson()
            ->connectTimeout((int) config('settlement.solidinvoice.connect_timeout_seconds', 5))
            ->timeout((int) config('settlement.solidinvoice.timeout_seconds', 15));
    }

    /** @param array<string, mixed> $data */
    private function toExternalInvoice(array $data, string $fallbackId): ExternalInvoice
    {
        $customFields = is_array($data['customFields'] ?? null)
            ? $data['customFields']
            : (is_array($data['custom_fields'] ?? null) ? $data['custom_fields'] : []);

        $clientCustomFields = (isset($data['client']) && is_array($data['client']) && isset($data['client']['custom_fields']) && is_array($data['client']['custom_fields']))
            ? $data['client']['custom_fields']
            : [];

        $recipient = (string) ($customFields['crypto_address'] ?? $clientCustomFields['crypto_address'] ?? '');
        $client = is_array($data['client'] ?? null) ? $this->clientIdentifier($data['client']) : null;

        if (is_string($data['client'] ?? null) && str_starts_with((string) $data['client'], '/api/clients/')) {
            $client ??= trim((string) $data['client']);

            try {
                $clientRes = $this->request()->get((string) $data['client']);
                if ($clientRes->successful()) {
                    $clientData = $clientRes->json();
                    if (is_array($clientData)) {
                        $client = $this->clientIdentifier($clientData) ?? $client;
                        $clientFields = is_array($clientData['customFields'] ?? null)
                            ? $clientData['customFields']
                            : (is_array($clientData['custom_fields'] ?? null) ? $clientData['custom_fields'] : []);
                        $recipient = $recipient !== '' ? $recipient : (string) ($clientFields['crypto_address'] ?? '');
                    }
                }
            } catch (\Throwable) {
                // Retain the IRI as the client reference if the expansion fails.
            }
        }

        return new ExternalInvoice(
            id: (string) ($data['id'] ?? $fallbackId),
            invoiceNumber: (string) ($data['invoiceId'] ?? $data['invoice_id'] ?? $fallbackId),
            recipient: $recipient,
            amount: (string) ($data['total'] ?? $data['payableAmount'] ?? '0.000000'),
            currency: (string) ($data['currency'] ?? 'USDC'),
            status: (string) ($data['status'] ?? 'unknown'),
            client: $client,
        );
    }

    /** @param array<string, mixed> $client */
    private function clientIdentifier(array $client): ?string
    {
        foreach (['name', 'id', '@id'] as $field) {
            $value = trim((string) ($client[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function markPaid(string $invoiceId, SettlementReceipt $receipt, string $idempotencyKey): void
    {
        $inv = $this->get($invoiceId);
        if (strtolower($inv->status) === 'paid') {
            return;
        }

        $currency = $inv->currency ?: 'USD';

        // Route through SolidInvoice's supported payment write path with external witness evidence
        $response = $this->request()
            ->withHeaders([
                'X-API-TOKEN' => $this->apiToken,
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->acceptJson()
            ->post("/api/invoices/{$invoiceId}/payments", [
                'amount' => (float) $inv->amount,
                'currency' => $currency === 'USDC' ? 'USD' : $currency,
                'method' => 'credit',
                'completed' => $receipt->confirmedAt->format('Y-m-d H:i:s'),
                'reference' => $idempotencyKey,
                'description' => "Settled via Arc Settlement Bridge. TX: {$receipt->txHash}",
            ]);

        if (! $response->successful()) {
            if (str_contains(strtolower($response->body()), 'status "paid"')
                || strtolower($this->get($invoiceId)->status) === 'paid') {
                return;
            }

            throw new RuntimeException("Failed to record payment in SolidInvoice for invoice {$invoiceId}: ".$response->body());
        }
    }

    public function name(): string
    {
        return 'solidinvoice';
    }
}
