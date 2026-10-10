<?php

declare(strict_types=1);

namespace App\Domain\Settlement\Contracts;

use App\Domain\Settlement\DTOs\ExternalInvoice;
use App\Domain\Settlement\DTOs\SettlementReceipt;

interface InvoiceGateway
{
    /**
     * Return invoices that the external ledger currently considers payable.
     *
     * This makes the Bridge an out-of-process poller: it reads SolidInvoice
     * through its public API and never requires a SolidInvoice code change.
     *
     * @return list<ExternalInvoice>
     */
    public function listEligible(int $limit): array;

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
