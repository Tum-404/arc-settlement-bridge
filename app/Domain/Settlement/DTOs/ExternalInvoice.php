<?php

declare(strict_types=1);

namespace App\Domain\Settlement\DTOs;

final readonly class ExternalInvoice
{
    public function __construct(
        public string $id,
        public string $invoiceNumber,
        public string $recipient,
        public string $amount,
        public string $currency,
        public string $status,
        public ?string $client = null,
    ) {}
}
