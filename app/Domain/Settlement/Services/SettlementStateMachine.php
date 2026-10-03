<?php

declare(strict_types=1);

namespace App\Domain\Settlement\Services;

use App\Domain\Settlement\Enums\SettlementStatus;
use App\Domain\Settlement\Exceptions\InvalidStateTransitionException;

final class SettlementStateMachine
{
    /**
     * Determine whether transition from $from to $to is valid.
     */
    public function canTransition(SettlementStatus $from, SettlementStatus $to): bool
    {
        return match ($from) {
            SettlementStatus::CREATED => in_array($to, [
                SettlementStatus::SUBMITTED,
                SettlementStatus::SUBMISSION_UNKNOWN,
                SettlementStatus::FAILED,
            ], true),

            SettlementStatus::SUBMISSION_UNKNOWN => in_array($to, [
                SettlementStatus::SUBMITTED,
                SettlementStatus::FAILED,
            ], true),

            SettlementStatus::SUBMITTED => in_array($to, [
                SettlementStatus::CONFIRMED,
                SettlementStatus::FAILED,
            ], true),

            default => false,
        };
    }

    /**
     * Enforce valid transition or throw InvalidStateTransitionException.
     *
     * @throws InvalidStateTransitionException
     */
    public function assertCanTransition(SettlementStatus $from, SettlementStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw InvalidStateTransitionException::invalid($from, $to);
        }
    }

    /**
     * Enforce that SUBMITTED != PAID and an invoice can only be marked PAID when CONFIRMED.
     */
    public function canReconcile(SettlementStatus $status): bool
    {
        return $status === SettlementStatus::CONFIRMED;
    }
}
