<?php

declare(strict_types=1);

namespace App\Domain\Settlement\DTOs;

final readonly class CreateSettlementCommand
{
    public function __construct(
        public string $source,
        public string $invoiceId,
        public string $recipient,
        public string $amount,
        public string $currency,
    ) {}
}
