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
        private readonly ?string $entitySecret = null,
    ) {}

    public function blockchain(): string
    {
        return $this->blockchain;
    }

    /**
     * Retrieve Circle Developer Public Key for entity secret encryption.
     */
    public function getPublicKey(): string
    {
        $response = $this->client()->get('/v1/w3s/config/entity/publicKey');

        if (! $response->successful()) {
            throw new RuntimeException("Circle API getPublicKey failed: HTTP {$response->status()} - {$response->body()}");
        }

        $publicKey = (string) ($response->json('data.publicKey') ?? '');

        if ($publicKey === '') {
            throw new RuntimeException('Circle public key response did not contain data.publicKey');
        }

        return $publicKey;
    }

    /**
     * Generate single-use entitySecretCiphertext using RSA-OAEP SHA-256.
     */
    public function generateEntitySecretCiphertext(): ?string
    {
        if (empty($this->entitySecret)) {
            return null;
        }

        $secretBinary = @hex2bin(trim($this->entitySecret));
        if ($secretBinary === false || strlen($secretBinary) !== 32) {
            throw new RuntimeException('CIRCLE_ENTITY_SECRET must be a 32-byte hexadecimal string (64 characters).');
        }

        $publicKey = $this->getPublicKey();

        $pubFile = tempnam(sys_get_temp_dir(), 'circle_pub_');
        $secretFile = tempnam(sys_get_temp_dir(), 'circle_sec_');
        $outFile = tempnam(sys_get_temp_dir(), 'circle_out_');

        if ($pubFile === false || $secretFile === false || $outFile === false) {
            throw new RuntimeException('Failed to allocate temporary files for entity secret encryption.');
        }

        try {
            file_put_contents($pubFile, $publicKey);
            file_put_contents($secretFile, $secretBinary);

            $cmd = sprintf(
                'openssl pkeyutl -encrypt -pubin -inkey %s -pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:sha256 -pkeyopt rsa_mgf1_md:sha256 -in %s -out %s',
                escapeshellarg($pubFile),
                escapeshellarg($secretFile),
                escapeshellarg($outFile),
            );

            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0 || ! file_exists($outFile)) {
                throw new RuntimeException("OpenSSL failed to encrypt entity secret (exit code {$exitCode}).");
            }

            $encrypted = file_get_contents($outFile);
            if ($encrypted === false || $encrypted === '') {
                throw new RuntimeException('Encrypted entity secret output is empty.');
            }

            return base64_encode($encrypted);
        } finally {
            @unlink($pubFile);
            @unlink($secretFile);
            @unlink($outFile);
        }
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
        ?string $tokenId = null,
    ): array {
        $payload = [
            'idempotencyKey' => $idempotencyKey,
            'walletId' => $walletId,
            'destinationAddress' => $recipientAddress,
            'amounts' => [(string) $amount],
            'feeLevel' => 'MEDIUM',
        ];

        if ($tokenId) {
            $payload['tokenId'] = $tokenId;
        } elseif ($tokenAddress) {
            $payload['tokenAddress'] = $tokenAddress;
            $payload['blockchain'] = $this->blockchain;
        } else {
            // Auto-detect USDC token from wallet balances
            $autoTokenId = $this->getUsdcTokenId($walletId);
            if ($autoTokenId !== null) {
                $payload['tokenId'] = $autoTokenId;
            } else {
                $payload['blockchain'] = $this->blockchain;
                $payload['tokenAddress'] = '';
            }
        }

        $ciphertext = $this->generateEntitySecretCiphertext();
        if ($ciphertext !== null) {
            $payload['entitySecretCiphertext'] = $ciphertext;
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
        $response = $this->client()->get("/v1/w3s/transactions/{$transactionId}");

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

    /**
     * Fetch developer-controlled wallet information and balances.
     *
     * @return array<string, mixed>
     */
    public function getWallet(string $walletId): array
    {
        $response = $this->client()->get("/v1/w3s/wallets/{$walletId}");

        if (! $response->successful()) {
            throw new RuntimeException("Circle API getWallet failed: HTTP {$response->status()} - {$response->body()}");
        }

        $data = $response->json('data.wallet') ?? $response->json('data') ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * Fetch wallet token balances.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getWalletBalances(string $walletId): array
    {
        $response = $this->client()->get("/v1/w3s/wallets/{$walletId}/balances");

        if (! $response->successful()) {
            throw new RuntimeException("Circle API getWalletBalances failed: HTTP {$response->status()} - {$response->body()}");
        }

        $balances = $response->json('data.tokenBalances');

        if (! is_array($balances)) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $result */
        $result = array_values(array_filter($balances, 'is_array'));

        return $result;
    }

    /**
     * Resolve USDC token ID from the wallet's available tokens.
     */
    public function getUsdcTokenId(string $walletId): ?string
    {
        try {
            $balances = $this->getWalletBalances($walletId);
            foreach ($balances as $item) {
                $token = $item['token'] ?? null;
                if (is_array($token) && strtoupper((string) ($token['symbol'] ?? '')) === 'USDC' && ! empty($token['id'])) {
                    return (string) $token['id'];
                }
            }
        } catch (\Throwable) {
            // Return null if balance lookup fails
        }

        return null;
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson();
    }
}
