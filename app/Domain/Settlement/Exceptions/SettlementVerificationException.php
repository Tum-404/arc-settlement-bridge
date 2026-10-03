<?php

declare(strict_types=1);

namespace App\Domain\Settlement\Exceptions;

use DomainException;

final class SettlementVerificationException extends DomainException
{
    public static function mismatch(string $field, string $expected, string $actual): self
    {
        return new self("Settlement confirmation verification failed for '{$field}': expected '{$expected}', got '{$actual}'.");
    }

    public static function notFound(string $providerTxId): self
    {
        return new self("No settlement found with provider transaction ID: {$providerTxId}");
    }
}
