<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Settlement\Models\Recipient;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class RecipientController extends Controller
{
    /**
     * List all registered recipients.
     */
    public function index(): JsonResponse
    {
        $recipients = Recipient::query()
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'recipients' => $recipients,
            'count' => $recipients->count(),
        ]);
    }

    /**
     * Store or update an approved recipient in the whitelist.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:255'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'crypto_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'network' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $network = strtoupper(trim((string) ($validated['network'] ?? 'ARC-TESTNET')));
        $isActive = (bool) ($validated['is_active'] ?? true);
        $clientId = trim($validated['client_id']);
        $cryptoAddress = trim($validated['crypto_address']);

        $walletOwner = Recipient::findByCryptoAddress($cryptoAddress);
        if ($walletOwner !== null && $walletOwner->client_id !== $clientId) {
            throw ValidationException::withMessages([
                'crypto_address' => ['This crypto wallet address is already assigned to another client.'],
            ]);
        }

        $recipient = Recipient::updateOrCreate(
            [
                'client_id' => $clientId,
                'network' => $network,
            ],
            [
                'client_name' => ! empty($validated['client_name']) ? trim($validated['client_name']) : $clientId,
                'crypto_address' => $cryptoAddress,
                'is_active' => $isActive,
            ]
        );

        return response()->json([
            'message' => 'Recipient registered successfully.',
            'recipient' => $recipient,
        ], Response::HTTP_CREATED);
    }

    /**
     * Identify the registered recipient that owns a wallet address.
     */
    public function showByWallet(string $cryptoAddress): JsonResponse
    {
        $recipient = Recipient::findByCryptoAddress($cryptoAddress);

        if ($recipient === null) {
            return response()->json(['message' => 'No recipient is registered for this crypto wallet address.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['recipient' => $recipient]);
    }

    /**
     * Toggle the active status of a recipient.
     */
    public function toggle(Recipient $recipient): JsonResponse
    {
        $recipient->update([
            'is_active' => ! $recipient->is_active,
        ]);

        return response()->json([
            'message' => 'Recipient status updated.',
            'recipient' => $recipient->fresh(),
        ]);
    }

    /**
     * Remove a recipient from the whitelist.
     */
    public function destroy(Recipient $recipient): JsonResponse
    {
        $recipient->delete();

        return response()->json([
            'message' => 'Recipient removed from whitelist.',
        ]);
    }
}
