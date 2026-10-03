<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Settlement\Commands\ConfirmSettlement;
use App\Application\Settlement\Commands\CreateSettlement;
use App\Domain\Settlement\DTOs\CreateSettlementCommand;
use App\Domain\Settlement\Exceptions\SettlementRequestConflictException;
use App\Domain\Settlement\Models\Settlement;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SettlementController extends Controller
{
    /**
     * Display a listing of settlements with their audit events.
     */
    public function index(): JsonResponse
    {
        $settlements = Settlement::with('events')
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $settlements,
        ]);
    }

    /**
     * Store a newly created settlement or safely return existing duplicate.
     */
    public function store(Request $request, CreateSettlement $createSettlement): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['required', 'string', 'max:50'],
            'invoice_id' => ['required', 'string', 'max:100'],
            'recipient' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'max:10'],
        ]);

        $command = new CreateSettlementCommand(
            source: $validated['source'],
            invoiceId: $validated['invoice_id'],
            recipient: $validated['recipient'],
            amount: (string) $validated['amount'],
            currency: $validated['currency'],
        );

        try {
            $result = $createSettlement->execute($command);
        } catch (SettlementRequestConflictException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        $settlement = $result['settlement'];
        $isDuplicate = $result['is_duplicate'];

        return response()->json([
            'id' => $settlement->id,
            'source' => $settlement->source,
            'source_invoice_id' => $settlement->source_invoice_id,
            'recipient' => $settlement->recipient,
            'amount' => $settlement->amount,
            'currency' => $settlement->currency,
            'status' => $settlement->status->value,
            'idempotency_key' => $settlement->idempotency_key,
            'provider' => $settlement->provider,
            'provider_transaction_id' => $settlement->provider_transaction_id,
            'tx_hash' => $settlement->tx_hash,
            'duplicate' => $isDuplicate,
            'events' => $settlement->events,
            'submitted_at' => $settlement->submitted_at?->toIso8601String(),
            'confirmed_at' => $settlement->confirmed_at?->toIso8601String(),
            'failed_at' => $settlement->failed_at?->toIso8601String(),
        ], $isDuplicate ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    /**
     * Display the specified settlement.
     */
    public function show(Settlement $settlement): JsonResponse
    {
        $settlement->load('events');

        return response()->json([
            'data' => $settlement,
        ]);
    }

    /**
     * Interactive endpoint to simulate external settlement confirmation.
     */
    public function simulateConfirm(Request $request, Settlement $settlement, ConfirmSettlement $confirmSettlement): JsonResponse
    {
        if ($settlement->provider_transaction_id === null) {
            return response()->json([
                'message' => 'Cannot confirm a settlement without provider_transaction_id.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $txHash = $request->input('tx_hash', $settlement->tx_hash ?? ('0x'.hash('sha256', (string) microtime(true))));

        $confirmed = $confirmSettlement->execute(
            providerTransactionId: $settlement->provider_transaction_id,
            evidence: [
                'provider_transaction_id' => $settlement->provider_transaction_id,
                'recipient' => $settlement->recipient,
                'amount' => $settlement->amount,
                'currency' => $settlement->currency,
                'tx_hash' => $txHash,
            ]
        );

        return response()->json([
            'message' => 'Settlement confirmed successfully.',
            'data' => $confirmed,
        ]);
    }
}
