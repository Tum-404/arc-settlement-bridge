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

    public static function recipientClientRequired(): self
    {
        return new self('Settlement requires a client_id when the source invoice has no client reference.');
    }

    public static function recipientNotFound(string $client): self
    {
        return new self("Client [{$client}] has no approved recipient configuration in the recipients table. Settlement rejected.");
    }

    public static function recipientAutoRegisteredInactive(string $client): self
    {
        return new self("Client [{$client}] was not registered in the recipients table. It has now been automatically added as INACTIVE to prevent user error. Please review and activate it on the dashboard before settling.");
    }

    public static function walletAlreadyAssigned(string $walletAddress, string $client): self
    {
        return new self("Wallet address [{$walletAddress}] is already assigned to client [{$client}]. Settlement rejected.");
    }

    public static function recipientInactive(string $client): self
    {
        return new self("Client [{$client}] is currently INACTIVE in the Approved Payees Directory. Please activate it on the dashboard before settling.");
    }
}
