<?php

declare(strict_types=1);

namespace App\Domain\Settlement\DTOs;

use DateTimeInterface;

final readonly class SettlementReceipt
{
    public function __construct(
        public ?string $txHash,
        public DateTimeInterface $confirmedAt,
        public ?string $provider = null,
        public ?string $providerTransactionId = null,
    ) {}
}
