<?php

declare(strict_types=1);

namespace App\Domain\Settlement\Services;

use Ramsey\Uuid\Uuid;

final class IdempotencyKeyGenerator
{
    /**
     * Generate a deterministic SHA-256 idempotency key from payment coordinates.
     */
    public function generate(
        string $source,
        string $invoiceId,
        string $recipient,
        string $amount,
        string $currency,
    ): string {
        $payload = implode('|', [
            strtolower(trim($source)),
            trim($invoiceId),
            strtolower(trim($recipient)),
            $this->normalizeAmount($amount),
            strtoupper(trim($currency)),
        ]);

        return hash('sha256', $payload);
    }

    /**
     * Normalize amount to fixed 6 decimal places without floating-point inaccuracies.
     *
     * @return numeric-string
     */
    public function normalizeAmount(string $amount): string
    {
        $clean = trim($amount);

        if (! is_numeric($clean)) {
            throw new \InvalidArgumentException("Amount must be numeric: {$amount}");
        }

        /** @var numeric-string $clean */
        return bcadd($clean, '0', 6);
    }

    public function providerKey(string $settlementIdempotencyKey): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'arc-settlement-bridge:'.$settlementIdempotencyKey)->toString();
    }
}
