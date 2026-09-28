<?php

declare(strict_types=1);

namespace App\Infrastructure\Arc;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

final class CircleClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.circle.com',
        private readonly string $blockchain = 'ARC-TESTNET',
    ) {}

    public function blockchain(): string
    {
        return $this->blockchain;
    }

    /**
     * Transfer USDC from developer-controlled wallet to recipient on Arc Testnet.
     *
     * @return array{id: string, state: string, txHash: ?string}
     */
    public function transferUsdc(
        string $walletId,
        string $recipientAddress,
        string $amount,
        string $idempotencyKey,
        ?string $tokenAddress = null,
    ): array {
        $payload = [
            'idempotencyKey' => $idempotencyKey,
            'walletId' => $walletId,
            'destinationAddress' => $recipientAddress,
            'amounts' => [(string) $amount],
            'feeLevel' => 'MEDIUM',
        ];

        if ($tokenAddress) {
            $payload['tokenAddress'] = $tokenAddress;
        }

        $response = $this->client()->post('/v1/w3s/developer/transactions/transfer', $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Circle API transfer failed: HTTP {$response->status()} - {$response->body()}");
        }

        $data = $response->json('data') ?? [];

        return [
            'id' => (string) ($data['id'] ?? $idempotencyKey),
            'state' => (string) ($data['state'] ?? 'INITIATED'),
            'txHash' => isset($data['txHash']) ? (string) $data['txHash'] : null,
        ];
    }

    /**
     * Fetch status of a transaction by its Circle transaction ID.
     *
     * @return array{id: string, state: string, txHash: ?string, errorCode: ?string, errorReason: ?string}
     */
    public function getTransaction(string $transactionId): array
    {
        $response = $this->client()->get("/v1/w3s/developer/transactions/{$transactionId}");

        if (! $response->successful()) {
            throw new RuntimeException("Circle API getTransaction failed: HTTP {$response->status()} - {$response->body()}");
        }

        $data = $response->json('data.transaction') ?? $response->json('data') ?? [];

        return [
            'id' => (string) ($data['id'] ?? $transactionId),
            'state' => (string) ($data['state'] ?? 'PENDING'),
            'txHash' => isset($data['txHash']) ? (string) $data['txHash'] : null,
            'errorCode' => isset($data['errorCode']) ? (string) $data['errorCode'] : null,
            'errorReason' => isset($data['errorReason']) ? (string) $data['errorReason'] : null,
        ];
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson();
    }
}
