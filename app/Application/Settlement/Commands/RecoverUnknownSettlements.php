<?php

declare(strict_types=1);

namespace App\Application\Settlement\Commands;

use App\Domain\Settlement\Contracts\SettlementProvider;
use App\Domain\Settlement\Enums\SettlementStatus;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Services\SettlementStateMachine;

final class RecoverUnknownSettlements
{
    public function __construct(
        private readonly SettlementProvider $provider,
        private readonly SettlementStateMachine $stateMachine,
    ) {}

    public function execute(): int
    {
        $recovered = 0;
        Settlement::query()->where('status', SettlementStatus::SUBMISSION_UNKNOWN)->each(function (Settlement $settlement) use (&$recovered): void {
            $result = $this->provider->submit($settlement);
            $this->stateMachine->assertCanTransition($settlement->status, SettlementStatus::SUBMITTED);
            $settlement->update([
                'status' => SettlementStatus::SUBMITTED,
                'provider' => $result->provider ?? $this->provider->name(),
                'provider_transaction_id' => $result->providerTransactionId,
                'tx_hash' => $result->txHash,
                'submitted_at' => now(),
                'failure_code' => null,
                'failure_reason' => null,
            ]);
            $recovered++;
        });

        return $recovered;
    }
}
