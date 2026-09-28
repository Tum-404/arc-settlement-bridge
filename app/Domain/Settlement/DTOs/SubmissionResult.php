<?php

declare(strict_types=1);

namespace App\Domain\Settlement\DTOs;

final readonly class SubmissionResult
{
    public function __construct(
        public string $providerTransactionId,
        public ?string $txHash = null,
        public ?string $provider = null,
    ) {}
}
