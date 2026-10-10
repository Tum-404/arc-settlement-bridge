<?php

declare(strict_types=1);

namespace App\Infrastructure\SolidInvoice;

use Illuminate\Http\Request;

final class SolidInvoiceWebhookVerifier
{
    public function __construct(
        private readonly string $webhookSecret,
        private readonly int $maxAgeSeconds,
    ) {}

    /**
     * SolidInvoice integration callbacks are signed as:
     * HMAC-SHA256("{timestamp}.{raw request body}", webhook secret).
     */
    public function verify(Request $request): bool
    {
        if ($this->webhookSecret === '') {
            return false;
        }

        $timestamp = $request->header('X-SolidInvoice-Timestamp');
        $signature = $request->header('X-SolidInvoice-Signature');
        if (! is_string($timestamp) || ! is_string($signature)) {
            return false;
        }

        $timestampValue = strtotime($timestamp);
        if ($timestampValue === false || abs(now()->getTimestamp() - $timestampValue) > $this->maxAgeSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $this->webhookSecret);

        return hash_equals($expected, $signature);
    }
}
