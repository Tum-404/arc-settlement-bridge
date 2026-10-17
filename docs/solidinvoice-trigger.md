# SolidInvoice → Settlement Bridge ingestion

## Supported model: out-of-process polling

Do not install a SolidInvoice Symfony subscriber or modify SolidInvoice source
code. The Settlement Bridge is deployed separately and uses a configured
SolidInvoice API token to periodically read eligible `pending` and `overdue`
invoices:

```bash
php artisan settlements:poll-solidinvoice --limit=100
```

The poller skips invoices that are paid, cancelled, or already have an active
Bridge lifecycle. A timeout or unreachable SolidInvoice endpoint is logged with
the configured backoff interval and leaves all existing settlement state
unchanged. Each invoice is then independently checked again by the settlement
creation flow before any provider submission occurs.

## Legacy webhook compatibility

The webhook endpoint below remains available only for existing deployments.
New installations should use the poller above and need no SolidInvoice-side
code change.

The Bridge must receive a callback only when an invoice becomes ready for
settlement (`pending`). Never send a callback for `paid`: `paid` is the result
of an Arc-confirmed settlement, not its trigger.

## Endpoint

`POST /api/webhooks/solidinvoice`

The endpoint accepts only this payload shape:

```json
{
    "event_id": "a-stable-outbox-event-id",
    "event": "invoice.pending",
    "data": { "invoice_id": "solidinvoice-internal-id" }
}
```

The event ID must be stable across retries. The Bridge saves it with a unique
constraint, so retries are acknowledged without creating another transfer.

## Signature

Set the same high-entropy value in the Bridge and the SolidInvoice integration:

```env
SOLIDINVOICE_WEBHOOK_SECRET=replace-with-a-long-random-secret
```

Send these headers:

```text
X-SolidInvoice-Timestamp: 2026-10-03T10:00:00+00:00
X-SolidInvoice-Signature: hex-hmac-sha256(timestamp + "." + raw_request_body, secret)
Content-Type: application/json
```

Callbacks older than five minutes are rejected by default. Set
`SOLIDINVOICE_WEBHOOK_MAX_AGE_SECONDS` only when a longer outbox delay is
intentional.

## Legacy subscriber behavior

Only existing deployments that already use the legacy webhook compatibility
endpoint should install a small SolidInvoice/Symfony event subscriber that observes the
invoice workflow transition to `pending`. It should write an outbox record in
the same database transaction, then a worker should deliver the signed request
above. Do not make the HTTP request within the invoice status transaction.

The callback contains only the invoice ID. The Bridge reads the invoice again
using its configured SolidInvoice API token and independently verifies the
recipient (`crypto_address`), amount, currency, and that the invoice is still
eligible before submitting to Arc.

## Troubleshooting

Follow the live structured log while testing:

```bash
tail -f storage/logs/settlement-*.log
```

For Docker:

```bash
docker compose exec bridge tail -f storage/logs/settlement-*.log
```

Every settlement state change and reconciliation failure is also appended to
the database `settlement_events` ledger and shown in the Bridge dashboard.
