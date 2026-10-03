<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Domain\Settlement\Contracts\SettlementProvider;
use App\Domain\Settlement\DTOs\SubmissionResult;
use App\Domain\Settlement\Enums\ProviderSettlementStatus;
use App\Domain\Settlement\Models\Settlement;
use Illuminate\Support\Str;
use RuntimeException;

final class FakeSettlementProvider implements SettlementProvider
{
    private static bool $shouldFailSubmission = false;

    private static ?ProviderSettlementStatus $forcedStatus = null;

    public function submit(Settlement $settlement): SubmissionResult
    {
        if (self::$shouldFailSubmission || str_contains($settlement->recipient, 'fail')) {
            throw new RuntimeException('Simulated provider transfer failure: insufficient network funds or rejected.');
        }

        $providerTxId = 'fake-'.Str::uuid()->toString();
        $txHash = '0x'.hash('sha256', $settlement->idempotency_key.'-'.microtime(true));

        return new SubmissionResult(
            providerTransactionId: $providerTxId,
            txHash: $txHash,
            provider: $this->name(),
        );
    }

    public function status(string $providerTransactionId): ProviderSettlementStatus
    {
        if (self::$forcedStatus !== null) {
            return self::$forcedStatus;
        }

        return ProviderSettlementStatus::CONFIRMED;
    }

    public function name(): string
    {
        return 'fake';
    }

    public static function simulateFailure(bool $fail = true): void
    {
        self::$shouldFailSubmission = $fail;
    }

    public static function setForcedStatus(?ProviderSettlementStatus $status): void
    {
        self::$forcedStatus = $status;
    }

    public static function reset(): void
    {
        self::$shouldFailSubmission = false;
        self::$forcedStatus = null;
    }
}
