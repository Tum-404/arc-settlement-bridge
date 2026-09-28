<?php

declare(strict_types=1);

namespace App\Domain\Settlement\Contracts;

use App\Domain\Settlement\DTOs\SubmissionResult;
use App\Domain\Settlement\Enums\ProviderSettlementStatus;
use App\Domain\Settlement\Models\Settlement;

interface SettlementProvider
{
    /**
     * Submit a settlement to the provider (Arc/Circle or Fake).
     */
    public function submit(Settlement $settlement): SubmissionResult;

    /**
     * Query status from the provider.
     */
    public function status(string $providerTransactionId): ProviderSettlementStatus;

    /**
     * Get provider name identifier.
     */
    public function name(): string;
}
