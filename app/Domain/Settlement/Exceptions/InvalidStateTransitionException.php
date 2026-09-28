<?php

declare(strict_types=1);

namespace App\Domain\Settlement\Exceptions;

use App\Domain\Settlement\Enums\SettlementStatus;
use DomainException;

final class InvalidStateTransitionException extends DomainException
{
    public static function invalid(SettlementStatus $from, SettlementStatus $to): self
    {
        return new self("Cannot transition settlement status from '{$from->value}' to '{$to->value}'.");
    }

    public static function cannotReconcile(SettlementStatus $current): self
    {
        return new self("Cannot reconcile settlement in '{$current->value}' state. Settlement must be in 'confirmed' state.");
    }
}
