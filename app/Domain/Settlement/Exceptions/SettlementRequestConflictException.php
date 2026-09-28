<?php

declare(strict_types=1);

namespace App\Domain\Settlement\Exceptions;

use RuntimeException;

final class SettlementRequestConflictException extends RuntimeException
{
    public static function forInvoice(string $source, string $invoiceId): self
    {
        return new self("A settlement already exists for {$source} invoice {$invoiceId} with different payment coordinates.");
    }

    public static function invoiceMismatch(string $field): self
    {
        return new self("Settlement request does not match the source invoice {$field}.");
    }
}
