<?php

declare(strict_types=1);

namespace App\Infrastructure\Arc;

use Illuminate\Http\Request;

final class CircleWebhookVerifier
{
    public function __construct(
        private readonly ?string $webhookSecret = null,
    ) {}

    /**
     * Verify whether the incoming webhook request carries a valid signature.
     */
    public function verify(Request $request): bool
    {
        // Unsigned callbacks are permitted only for explicitly local/test operation.
        if (empty($this->webhookSecret)) {
            return (bool) config('settlement.arc.allow_unsigned_webhooks', false)
                && in_array(config('app.env'), ['local', 'testing'], true);
        }

        $signature = $request->header('X-Circle-Signature') ?? $request->header('X-Webhook-Signature');

        if (! $signature) {
            return false;
        }

        $payload = $request->getContent();
        $expected = hash_hmac('sha256', $payload, $this->webhookSecret);

        return hash_equals($expected, $signature);
    }
}
