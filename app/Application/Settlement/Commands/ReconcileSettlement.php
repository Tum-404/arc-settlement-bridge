<?php

declare(strict_types=1);

namespace App\Application\Settlement\Commands;

use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\DTOs\SettlementReceipt;
use App\Domain\Settlement\Enums\SettlementEventType;
use App\Domain\Settlement\Exceptions\InvalidStateTransitionException;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementEvent;
use App\Domain\Settlement\Services\SettlementStateMachine;
use Illuminate\Support\Facades\DB;

final class ReconcileSettlement
{
    public function __construct(
        private readonly InvoiceGateway $invoiceGateway,
        private readonly SettlementStateMachine $stateMachine,
    ) {}

    /**
     * Mark the invoice as PAID in external invoice system using verified settlement evidence.
     *
     * @throws InvalidStateTransitionException
     */
    public function execute(Settlement $settlement): void
    {
        $settlement = DB::transaction(function () use ($settlement): Settlement {
            /** @var Settlement $locked */
            $locked = Settlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if (! $this->stateMachine->canReconcile($locked->status)) {
                throw InvalidStateTransitionException::cannotReconcile($locked->status);
            }
            if ($locked->reconciliation_status === 'succeeded') {
                return $locked;
            }
            $locked->update(['reconciliation_status' => 'processing']);

            return $locked->fresh();
        });

        $receipt = new SettlementReceipt(
            txHash: $settlement->tx_hash,
            confirmedAt: $settlement->confirmed_at ?? now(),
            provider: $settlement->provider,
            providerTransactionId: $settlement->provider_transaction_id,
        );

        $this->invoiceGateway->markPaid(
            invoiceId: $settlement->source_invoice_id,
            receipt: $receipt,
            idempotencyKey: $settlement->idempotency_key,
        );

        DB::transaction(function () use ($settlement): void {
            /** @var Settlement $locked */
            $locked = Settlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($locked->reconciliation_status === 'succeeded') {
                return;
            }
            $locked->update(['reconciliation_status' => 'succeeded', 'reconciled_at' => now()]);
            SettlementEvent::create([
                'settlement_id' => $locked->id,
                'type' => SettlementEventType::INVOICE_RECONCILED,
                'payload' => [
                    'source' => $locked->source,
                    'source_invoice_id' => $locked->source_invoice_id,
                    'tx_hash' => $locked->tx_hash,
                    'provider' => $locked->provider,
                    'confirmed_at' => $locked->confirmed_at?->toIso8601String(),
                ],
            ]);
        });
    }
}
