<?php

declare(strict_types=1);

namespace App\Application\Settlement\Commands;

use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\Contracts\SettlementProvider;
use App\Domain\Settlement\DTOs\CreateSettlementCommand;
use App\Domain\Settlement\Enums\SettlementEventType;
use App\Domain\Settlement\Enums\SettlementStatus;
use App\Domain\Settlement\Exceptions\SettlementRequestConflictException;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementEvent;
use App\Domain\Settlement\Services\IdempotencyKeyGenerator;
use App\Domain\Settlement\Services\SettlementStateMachine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CreateSettlement
{
    public function __construct(
        private readonly IdempotencyKeyGenerator $idempotencyKeyGenerator,
        private readonly SettlementStateMachine $stateMachine,
        private readonly SettlementProvider $provider,
        private readonly InvoiceGateway $invoiceGateway,
    ) {}

    /**
     * Create or retrieve an idempotent settlement and submit to provider.
     *
     * @return array{settlement: Settlement, is_duplicate: bool}
     */
    public function execute(CreateSettlementCommand $command): array
    {
        $idempotencyKey = $this->idempotencyKeyGenerator->generate(
            source: $command->source,
            invoiceId: $command->invoiceId,
            recipient: $command->recipient,
            amount: $command->amount,
            currency: $command->currency,
        );

        $normalizedAmount = $this->idempotencyKeyGenerator->normalizeAmount($command->amount);

        // An exact retry is valid even after the external invoice has become paid.
        $existing = Settlement::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            $this->recordDuplicateAttempt($existing);

            return ['settlement' => $existing, 'is_duplicate' => true];
        }

        $invoiceSettlement = Settlement::where('source', strtolower(trim($command->source)))
            ->where('source_invoice_id', trim($command->invoiceId))
            ->first();
        if ($invoiceSettlement !== null) {
            $this->recordRejectedAttempt($invoiceSettlement, $command);
            throw SettlementRequestConflictException::forInvoice($command->source, $command->invoiceId);
        }

        $invoice = $this->invoiceGateway->get(trim($command->invoiceId));
        if (strtolower($invoice->status) !== 'unpaid') {
            throw SettlementRequestConflictException::forInvoice($command->source, $command->invoiceId);
        }
        if (strtolower(trim($invoice->recipient)) !== strtolower(trim($command->recipient))) {
            throw SettlementRequestConflictException::invoiceMismatch('recipient');
        }
        if (bccomp($this->idempotencyKeyGenerator->normalizeAmount($invoice->amount), $normalizedAmount, 6) !== 0) {
            throw SettlementRequestConflictException::invoiceMismatch('amount');
        }
        if (strtoupper(trim($invoice->currency)) !== strtoupper(trim($command->currency))) {
            throw SettlementRequestConflictException::invoiceMismatch('currency');
        }

        /** @var Settlement|null $settlement */
        $settlement = null;
        $isDuplicate = false;

        DB::transaction(function () use (
            $command,
            $idempotencyKey,
            $normalizedAmount,
            &$settlement,
            &$isDuplicate
        ): void {
            try {
                $settlement = Settlement::create([
                    'source' => strtolower(trim($command->source)),
                    'source_invoice_id' => trim($command->invoiceId),
                    'recipient' => trim($command->recipient),
                    'amount' => $normalizedAmount,
                    'currency' => strtoupper(trim($command->currency)),
                    'idempotency_key' => $idempotencyKey,
                    'provider_idempotency_key' => $this->idempotencyKeyGenerator->providerKey($idempotencyKey),
                    'status' => SettlementStatus::CREATED,
                ]);

                SettlementEvent::create([
                    'settlement_id' => $settlement->id,
                    'type' => SettlementEventType::SETTLEMENT_CREATED,
                    'payload' => [
                        'source' => $settlement->source,
                        'invoice_id' => $settlement->source_invoice_id,
                        'amount' => $settlement->amount,
                        'currency' => $settlement->currency,
                        'recipient' => $settlement->recipient,
                    ],
                ]);
            } catch (UniqueConstraintViolationException) {
                // Concurrent race condition caught by database unique constraint
                $settlement = Settlement::where('idempotency_key', $idempotencyKey)->first();
                if ($settlement === null) {
                    $invoiceSettlement = Settlement::where('source', strtolower(trim($command->source)))
                        ->where('source_invoice_id', trim($command->invoiceId))
                        ->firstOrFail();
                    $this->recordRejectedAttempt($invoiceSettlement, $command);
                    throw SettlementRequestConflictException::forInvoice($command->source, $command->invoiceId);
                }
                $isDuplicate = true;
            }
        });

        if ($isDuplicate && $settlement !== null) {
            $this->recordDuplicateAttempt($settlement);

            return ['settlement' => $settlement, 'is_duplicate' => true];
        }

        // If newly created, submit to payment provider
        if ($settlement !== null && $settlement->isCreated()) {
            $this->submitToProvider($settlement);
        }

        return ['settlement' => $settlement->fresh(['events']), 'is_duplicate' => false];
    }

    private function recordDuplicateAttempt(Settlement $settlement): void
    {
        SettlementEvent::create([
            'settlement_id' => $settlement->id,
            'type' => SettlementEventType::DUPLICATE_REQUEST_RECEIVED,
            'payload' => [
                'current_status' => $settlement->status->value,
                'idempotency_key' => $settlement->idempotency_key,
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    private function recordRejectedAttempt(Settlement $settlement, CreateSettlementCommand $command): void
    {
        SettlementEvent::create([
            'settlement_id' => $settlement->id,
            'type' => SettlementEventType::SETTLEMENT_REQUEST_REJECTED,
            'payload' => ['reason' => 'immutable_invoice_coordinates', 'requested_amount' => $command->amount],
        ]);
    }

    private function submitToProvider(Settlement $settlement): void
    {
        try {
            $result = $this->provider->submit($settlement);

            $this->stateMachine->assertCanTransition(
                from: $settlement->status,
                to: SettlementStatus::SUBMITTED,
            );

            $settlement->update([
                'status' => SettlementStatus::SUBMITTED,
                'provider' => $result->provider ?? $this->provider->name(),
                'provider_transaction_id' => $result->providerTransactionId,
                'tx_hash' => $result->txHash,
                'submitted_at' => now(),
            ]);

            SettlementEvent::create([
                'settlement_id' => $settlement->id,
                'type' => SettlementEventType::PAYMENT_SUBMITTED,
                'payload' => [
                    'provider' => $settlement->provider,
                    'provider_transaction_id' => $result->providerTransactionId,
                    'tx_hash' => $result->txHash,
                ],
            ]);
        } catch (ConnectionException $e) {
            $this->stateMachine->assertCanTransition($settlement->status, SettlementStatus::SUBMISSION_UNKNOWN);
            $settlement->update([
                'status' => SettlementStatus::SUBMISSION_UNKNOWN,
                'provider' => $this->provider->name(),
                'failure_code' => 'SUBMISSION_AMBIGUOUS',
                'failure_reason' => $e->getMessage(),
            ]);
            SettlementEvent::create([
                'settlement_id' => $settlement->id,
                'type' => SettlementEventType::PROVIDER_TIMEOUT,
                'payload' => ['reason' => $e->getMessage()],
            ]);
        } catch (Throwable $e) {
            $this->stateMachine->assertCanTransition(
                from: $settlement->status,
                to: SettlementStatus::FAILED,
            );

            $settlement->update([
                'status' => SettlementStatus::FAILED,
                'provider' => $this->provider->name(),
                'failure_code' => 'SUBMISSION_FAILED',
                'failure_reason' => $e->getMessage(),
                'failed_at' => now(),
            ]);

            SettlementEvent::create([
                'settlement_id' => $settlement->id,
                'type' => SettlementEventType::SETTLEMENT_FAILED,
                'payload' => [
                    'failure_code' => 'SUBMISSION_FAILED',
                    'reason' => $e->getMessage(),
                ],
            ]);
        }
    }
}
