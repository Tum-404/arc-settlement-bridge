<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Settlement\Commands\ConfirmSettlement;
use App\Domain\Settlement\Exceptions\SettlementVerificationException;
use App\Domain\Settlement\Models\ProviderWebhookEvent;
use App\Http\Controllers\Controller;
use App\Infrastructure\Arc\CircleWebhookVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ArcWebhookController extends Controller
{
    public function __construct(
        private readonly CircleWebhookVerifier $verifier,
        private readonly ConfirmSettlement $confirmSettlement,
    ) {}

    /**
     * Handle incoming webhook notifications from Circle / Arc.
     */
    public function handle(Request $request): JsonResponse
    {
        // 1. Verify cryptographic signature
        if (! $this->verifier->verify($request)) {
            return response()->json([
                'message' => 'Invalid webhook signature.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $payload = $request->json()->all();
        $eventId = (string) ($payload['eventId'] ?? $payload['id'] ?? $request->header('Circle-Event-Id') ?? hash('sha256', $request->getContent()));

        // 2. Enforce webhook idempotency
        try {
            $webhookRecord = ProviderWebhookEvent::create([
                'provider' => 'arc',
                'provider_event_id' => $eventId,
                'payload' => $payload,
            ]);
        } catch (UniqueConstraintViolationException) {
            $webhookRecord = ProviderWebhookEvent::where('provider_event_id', $eventId)->firstOrFail();
            if ($webhookRecord->processing_status === 'processed') {
                return response()->json([
                    'status' => 'acknowledged',
                    'duplicate' => true,
                    'event_id' => $eventId,
                ], Response::HTTP_OK);
            }
        }

        $webhookRecord->increment('attempts');
        $webhookRecord->update(['processing_status' => 'processing', 'last_error' => null]);

        // 3. Extract transaction details
        $transaction = $payload['data']['transaction'] ?? $payload['transaction'] ?? $payload;
        $state = strtoupper((string) ($transaction['state'] ?? $transaction['status'] ?? ''));
        $providerTxId = (string) ($transaction['id'] ?? $transaction['transactionId'] ?? $transaction['provider_transaction_id'] ?? '');

        if (empty($providerTxId)) {
            $webhookRecord->update(['processing_status' => 'processed', 'processed_at' => now()]);

            return response()->json([
                'status' => 'ignored',
                'reason' => 'No provider transaction ID in payload',
            ], Response::HTTP_OK);
        }

        // 4. Process confirmed transactions
        if (in_array($state, ['COMPLETE', 'CONFIRMED', 'CLEARED'], true)) {
            $evidence = [
                'provider_transaction_id' => $providerTxId,
                'recipient' => isset($transaction['destinationAddress']) ? (string) $transaction['destinationAddress'] : (isset($transaction['recipient']) ? (string) $transaction['recipient'] : null),
                'amount' => isset($transaction['amounts'][0]) ? (string) $transaction['amounts'][0] : (isset($transaction['amount']) ? (string) $transaction['amount'] : null),
                'currency' => isset($transaction['currency']) ? (string) $transaction['currency'] : null,
                'tx_hash' => isset($transaction['txHash']) ? (string) $transaction['txHash'] : (isset($transaction['tx_hash']) ? (string) $transaction['tx_hash'] : null),
            ];

            // Filter null values so only provided fields are strictly verified
            $evidence = array_filter($evidence, fn ($val) => $val !== null);

            try {
                $this->confirmSettlement->execute($providerTxId, $evidence);
            } catch (Throwable $e) {
                $isValidationError = $e instanceof SettlementVerificationException;
                $webhookRecord->update([
                    'processing_status' => 'failed',
                    'last_error' => $e->getMessage(),
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ], $isValidationError ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        $webhookRecord->update(['processing_status' => 'processed', 'processed_at' => now()]);

        return response()->json([
            'status' => 'processed',
            'event_id' => $eventId,
        ], Response::HTTP_OK);
    }
}
