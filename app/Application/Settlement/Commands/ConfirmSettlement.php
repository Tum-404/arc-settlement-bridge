<?php

declare(strict_types=1);

namespace App\Application\Settlement\Commands;

use App\Domain\Settlement\Enums\SettlementEventType;
use App\Domain\Settlement\Enums\SettlementStatus;
use App\Domain\Settlement\Exceptions\SettlementVerificationException;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementEvent;
use App\Domain\Settlement\Services\IdempotencyKeyGenerator;
use App\Domain\Settlement\Services\SettlementStateMachine;
use Illuminate\Support\Facades\DB;

final class ConfirmSettlement
{
    public function __construct(
        private readonly SettlementStateMachine $stateMachine,
        private readonly ReconcileSettlement $reconcileSettlement,
        private readonly IdempotencyKeyGenerator $idempotencyKeyGenerator,
    ) {}

    /**
     * Verify external transaction evidence and confirm settlement idempotently.
     *
     * @param array{
     *     provider_transaction_id: string,
     *     recipient?: string,
     *     amount?: string,
     *     currency?: string,
     *     tx_hash?: string|null,
     * } $evidence
     *
     * @throws SettlementVerificationException
     */
    public function execute(string $providerTransactionId, array $evidence): Settlement
    {
        foreach (['recipient', 'amount', 'currency', 'tx_hash'] as $required) {
            if (empty($evidence[$required])) {
                throw SettlementVerificationException::mismatch($required, 'present', 'missing');
            }
        }

        $settlement = DB::transaction(function () use ($providerTransactionId, $evidence): Settlement {
            /** @var Settlement|null $settlement */
            $settlement = Settlement::query()->lockForUpdate()
                ->where('provider_transaction_id', $providerTransactionId)
                ->first();
            if ($settlement === null) {
                throw SettlementVerificationException::notFound($providerTransactionId);
            }
            if ($settlement->isConfirmed()) {
                return $settlement;
            }

            $expected = strtolower(trim($settlement->recipient));
            $actual = strtolower(trim($evidence['recipient']));
            if ($expected !== $actual) {
                throw SettlementVerificationException::mismatch('recipient', $expected, $actual);
            }

            $expected = $this->idempotencyKeyGenerator->normalizeAmount($settlement->amount);
            $actual = $this->idempotencyKeyGenerator->normalizeAmount($evidence['amount']);
            if (bccomp($expected, $actual, 6) !== 0) {
                throw SettlementVerificationException::mismatch('amount', $expected, $actual);
            }

            $expected = strtoupper(trim($settlement->currency));
            $actual = strtoupper(trim($evidence['currency']));
            if ($expected !== $actual) {
                throw SettlementVerificationException::mismatch('currency', $expected, $actual);
            }

            $this->stateMachine->assertCanTransition(
                from: $settlement->status,
                to: SettlementStatus::CONFIRMED,
            );

            $updates = [
                'status' => SettlementStatus::CONFIRMED,
                'confirmed_at' => now(),
            ];

            $updates['tx_hash'] = $evidence['tx_hash'];

            $settlement->update($updates);

            SettlementEvent::create([
                'settlement_id' => $settlement->id,
                'type' => SettlementEventType::SETTLEMENT_CONFIRMED,
                'payload' => [
                    'provider_transaction_id' => $settlement->provider_transaction_id,
                    'tx_hash' => $settlement->tx_hash,
                    'confirmed_at' => $settlement->confirmed_at?->toIso8601String(),
                ],
            ]);

            $settlement->update(['reconciliation_status' => 'pending']);
            SettlementEvent::create([
                'settlement_id' => $settlement->id,
                'type' => SettlementEventType::RECONCILIATION_QUEUED,
                'payload' => ['provider_transaction_id' => $settlement->provider_transaction_id],
            ]);

            return $settlement->fresh();
        });

        if ($settlement->isConfirmed() && $settlement->reconciliation_status !== 'succeeded') {
            $this->reconcileSettlement->execute($settlement);
        }

        return $settlement->fresh(['events']);
    }
}
