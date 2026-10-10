<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Settlement\Commands\CreateSettlement;
use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\DTOs\CreateSettlementCommand;
use App\Domain\Settlement\Exceptions\SettlementRequestConflictException;
use App\Domain\Settlement\Models\ProviderWebhookEvent;
use App\Http\Controllers\Controller;
use App\Infrastructure\SolidInvoice\SolidInvoiceWebhookVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SolidInvoiceWebhookController extends Controller
{
    public function __construct(
        private readonly SolidInvoiceWebhookVerifier $verifier,
        private readonly InvoiceGateway $invoiceGateway,
        private readonly CreateSettlement $createSettlement,
    ) {}

    /**
     * Receives a signed, outbox-delivered invoice.pending event from the
     * SolidInvoice integration. Money coordinates are deliberately not trusted
     * from the callback and are instead read from SolidInvoice by the bridge.
     */
    public function handle(Request $request): JsonResponse
    {
        if (! $this->verifier->verify($request)) {
            Log::channel('settlement')->warning('Rejected SolidInvoice callback with invalid signature or timestamp.');

            return response()->json(['message' => 'Invalid SolidInvoice callback signature.'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = $request->json()->all();
        $eventId = (string) ($payload['event_id'] ?? $payload['id'] ?? $request->header('X-SolidInvoice-Event-Id') ?? '');
        $event = (string) ($payload['event'] ?? $payload['type'] ?? '');
        $invoiceId = (string) ($payload['data']['invoice_id'] ?? $payload['data']['invoice']['id'] ?? '');

        if ($eventId === '' || $event !== 'invoice.pending' || $invoiceId === '') {
            return response()->json([
                'message' => 'Expected event_id, event "invoice.pending", and data.invoice_id.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $webhookEvent = ProviderWebhookEvent::create([
                'provider' => 'solidinvoice',
                'provider_event_id' => $eventId,
                'payload' => $payload,
                'processing_status' => 'processing',
                'attempts' => 1,
            ]);
        } catch (UniqueConstraintViolationException) {
            $webhookEvent = ProviderWebhookEvent::query()
                ->where('provider', 'solidinvoice')
                ->where('provider_event_id', $eventId)
                ->firstOrFail();
            if ($webhookEvent->processing_status === 'processed') {
                return response()->json([
                    'status' => 'acknowledged',
                    'duplicate' => true,
                    'event_id' => $eventId,
                ]);
            }

            // A prior delivery may have failed because SolidInvoice or the
            // settlement provider was temporarily unavailable. Reusing the
            // event ID is safe: CreateSettlement has its own idempotency key.
            $webhookEvent->increment('attempts');
            $webhookEvent->update(['processing_status' => 'processing', 'last_error' => null]);
        }

        try {
            $invoice = $this->invoiceGateway->get($invoiceId);
            $result = $this->createSettlement->execute(new CreateSettlementCommand(
                source: 'solidinvoice',
                invoiceId: $invoice->id,
                recipient: $invoice->recipient,
                amount: $invoice->amount,
                currency: $invoice->currency,
            ));

            $webhookEvent->update(['processing_status' => 'processed', 'processed_at' => now()]);
            Log::channel('settlement')->info('SolidInvoice invoice.pending callback processed.', [
                'event_id' => $eventId,
                'invoice_id' => $invoiceId,
                'settlement_id' => $result['settlement']->id,
                'duplicate' => $result['is_duplicate'],
            ]);

            return response()->json([
                'status' => 'processed',
                'event_id' => $eventId,
                'settlement_id' => $result['settlement']->id,
                'duplicate' => $result['is_duplicate'],
            ], $result['is_duplicate'] ? Response::HTTP_OK : Response::HTTP_CREATED);
        } catch (Throwable $e) {
            $webhookEvent->update([
                'processing_status' => 'failed',
                'last_error' => $e->getMessage(),
            ]);
            Log::channel('settlement')->error('SolidInvoice callback processing failed.', [
                'event_id' => $eventId,
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            $status = $e instanceof SettlementRequestConflictException
                ? Response::HTTP_UNPROCESSABLE_ENTITY
                : Response::HTTP_INTERNAL_SERVER_ERROR;

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $status);
        }
    }
}
