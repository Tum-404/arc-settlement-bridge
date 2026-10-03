# Roadmap

## MVP status

The deterministic settlement core is implemented and verified locally: `php artisan test` passes with 20 tests / 159 assertions, PHPStan is clean, and Pint passes.

### Completed

- [x] Laravel application, Docker Compose, database migrations, and environment template
- [x] Settlement model, append-only settlement events, provider webhook-event persistence
- [x] `SettlementProvider` abstraction with fake and Arc/Circle implementations
- [x] `InvoiceGateway` abstraction with fake and SolidInvoice implementations
- [x] `POST /api/settlements`, settlement lookup, dashboard timeline, and confirmation simulation
- [x] Deterministic request idempotency plus database uniqueness
- [x] Immutable one-settlement-per-source-invoice rule with request/invoice-coordinate validation
- [x] Provider UUIDv5 idempotency key persisted and reused on Arc retries
- [x] Lifecycle enforcement: `CREATED → SUBMITTED → CONFIRMED/FAILED`, with `SUBMISSION_UNKNOWN` for ambiguous timeouts
- [x] Reconciliation only after confirmed settlement; `SUBMITTED` never marks an invoice paid
- [x] Row-locked confirmation, append-only audit events, webhook replay protection, and durable reconciliation state
- [x] Fail-closed Arc webhook signature verification outside explicit local/testing configuration
- [x] Recovery command: `php artisan settlements:recover-unknown`
- [x] Tests for duplicates, invoice-coordinate conflicts, timeout handling, failures, incomplete evidence, webhook replay, and invalid transitions

## Remaining before Arc testnet demo

- [ ] Provision a Circle developer-controlled wallet and set `CIRCLE_API_KEY`, `ARC_WALLET_ID`, token address, and `ARC_WEBHOOK_SECRET`.
- [ ] Keep `SETTLEMENT_ALLOW_UNSIGNED_WEBHOOKS=false` when `SETTLEMENT_PROVIDER=arc`.
- [ ] Submit and reconcile one real Arc testnet USDC transaction; retain its provider ID and transaction hash as demo evidence.
- [ ] Run SolidInvoice locally, configure `INVOICE_GATEWAY=solidinvoice`, and confirm its payment endpoint accepts/retrieves the Bridge idempotency reference.
- [ ] Schedule `php artisan settlements:recover-unknown` in the deployment environment.
- [ ] Add an integration test against the configured Circle sandbox/webhook contract once credentials are available.

## Demo checklist

- [ ] Normal flow: invoice `UNPAID → SUBMITTED → CONFIRMED → PAID`.
- [ ] Duplicate pressure: 10 identical requests produce one settlement and one provider transfer.
- [ ] Failure/timeout: invoice stays unpaid; recovery uses the original provider idempotency key.
- [ ] Show the settlement event timeline and verified transaction evidence.

## Submission checklist

- [ ] Run a fresh-clone setup with the documented commands.
- [ ] Confirm no real credentials are committed.
- [ ] Verify Docker, README instructions, and demo configuration.
- [ ] Record a short backup demo video.
- [ ] Tag `v0.1.0`.

## Post-MVP

- [ ] Queue/scheduler deployment and operational monitoring/alerts.
- [ ] Additional invoice-system adapters: Invoice Ninja, ERPNext, and Odoo.
- [ ] Additional settlement providers.
- [ ] Optional AI recommendation plus explicit human authorization flow.
