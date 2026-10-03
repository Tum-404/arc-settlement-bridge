<?php

declare(strict_types=1);

namespace App\Infrastructure\Arc;

use App\Domain\Settlement\Contracts\SettlementProvider;
use App\Domain\Settlement\DTOs\SubmissionResult;
use App\Domain\Settlement\Enums\ProviderSettlementStatus;
use App\Domain\Settlement\Models\Settlement;

final class ArcSettlementProvider implements SettlementProvider
{
    public function __construct(
        private readonly CircleClient $circle,
        private readonly string $walletId,
        private readonly ?string $tokenAddress = null,
    ) {}

    public function submit(Settlement $settlement): SubmissionResult
    {
        $result = $this->circle->transferUsdc(
            walletId: $this->walletId,
            recipientAddress: $settlement->recipient,
            amount: (string) $settlement->amount,
            idempotencyKey: $settlement->provider_idempotency_key ?? $settlement->idempotency_key,
            tokenAddress: $this->tokenAddress,
        );

        return new SubmissionResult(
            providerTransactionId: $result['id'],
            txHash: $result['txHash'],
            provider: $this->name(),
        );
    }

    public function status(string $providerTransactionId): ProviderSettlementStatus
    {
        $transaction = $this->circle->getTransaction($providerTransactionId);

        return $this->mapStatus($transaction['state']);
    }

    public function name(): string
    {
        return 'arc';
    }

    private function mapStatus(string $state): ProviderSettlementStatus
    {
        return match (strtoupper($state)) {
            'COMPLETE', 'CONFIRMED', 'CLEARED' => ProviderSettlementStatus::CONFIRMED,
            'FAILED', 'DENIED', 'CANCELLED' => ProviderSettlementStatus::FAILED,
            'QUEUED', 'SENT', 'INITIATED', 'PENDING_RISK_SCREENING' => ProviderSettlementStatus::SUBMITTED,
            default => ProviderSettlementStatus::PENDING,
        };
    }
}
