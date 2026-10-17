<?php

declare(strict_types=1);

namespace App\Infrastructure\Arc;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CircleWebhookVerifier
{
    public function __construct(
        private readonly ?string $webhookSecret = null,
        private readonly ?string $apiKey = null,
        private readonly string $baseUrl = 'https://api.circle.com',
    ) {}

    /**
     * Verify whether the incoming webhook request carries a valid signature.
     */
    public function verify(Request $request): bool
    {
        $signature = $request->header('X-Circle-Signature') ?? $request->header('X-Webhook-Signature');
        $keyId = $request->header('X-Circle-Key-Id');

        // 1. Asymmetric ECDSA Verification (Official Circle v2 Webhook mechanism)
        if (! empty($signature) && ! empty($keyId)) {
            return $this->verifyCircleEcdsaSignature($request->getContent(), $signature, $keyId);
        }

        // 2. Symmetric HMAC-SHA256 Verification (Shared secret / Mock / Local test fallback)
        if (! empty($this->webhookSecret) && ! empty($signature)) {
            $payload = $request->getContent();
            $expected = hash_hmac('sha256', $payload, $this->webhookSecret);

            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        // 3. Unsigned callbacks are permitted only for explicitly local/test operation on non-arc provider
        if (config('settlement.provider') === 'arc') {
            return false;
        }

        return (bool) config('settlement.arc.allow_unsigned_webhooks', false)
            && in_array(config('app.env'), ['local', 'testing'], true);
    }

    /**
     * Verify ECDSA_SHA_256 signature using Circle's public key.
     */
    private function verifyCircleEcdsaSignature(string $payload, string $signature, string $keyId): bool
    {
        try {
            $publicKeyBase64 = Cache::remember("circle_webhook_pubkey_{$keyId}", now()->addHours(24), function () use ($keyId) {
                $apiKey = $this->apiKey ?: (string) config('settlement.arc.circle_api_key', '');
                $baseUrl = rtrim($this->baseUrl ?: (string) config('settlement.arc.base_url', 'https://api.circle.com'), '/');

                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Accept' => 'application/json',
                ])->timeout(10)->get("{$baseUrl}/v2/notifications/publicKey/{$keyId}");

                if (! $response->successful()) {
                    Log::channel('settlement')->warning('Failed to fetch Circle webhook public key', [
                        'key_id' => $keyId,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                }

                return $response->json('data.publicKey');
            });

            if (! $publicKeyBase64 || ! is_string($publicKeyBase64)) {
                return false;
            }

            $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($publicKeyBase64, 64, "\n")."-----END PUBLIC KEY-----\n";
            $binarySignature = base64_decode($signature, true);

            if ($binarySignature === false) {
                return false;
            }

            $verifyResult = openssl_verify($payload, $binarySignature, $pem, OPENSSL_ALGO_SHA256);

            return $verifyResult === 1;
        } catch (Throwable $e) {
            Log::channel('settlement')->error('Circle webhook signature verification error', [
                'key_id' => $keyId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
