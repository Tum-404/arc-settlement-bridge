<?php

declare(strict_types=1);

namespace App\Domain\Settlement\Contracts;

use App\Domain\Settlement\DTOs\ExternalInvoice;
use App\Domain\Settlement\DTOs\SettlementReceipt;

interface InvoiceGateway
{
    /**
     * Retrieve an invoice from external system (e.g. SolidInvoice).
     */
    public function get(string $invoiceId): ExternalInvoice;

    /**
     * Mark an invoice as PAID using verified external receipt evidence.
     */
    public function markPaid(string $invoiceId, SettlementReceipt $receipt, string $idempotencyKey): void;

    /**
     * Get gateway name identifier.
     */
    public function name(): string;
}
