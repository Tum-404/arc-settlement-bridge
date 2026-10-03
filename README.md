# Arc Settlement Bridge

> **“An invoice can be paid exactly once, and it is marked PAID only after the real settlement is confirmed.”**

Arc Settlement Bridge is a resilient financial gateway built in Laravel 12 that bridges open-source ERP/invoicing systems (such as [SolidInvoice](https://solidinvoice.co)) with programmable settlement networks (such as Circle's Arc Testnet USDC).

---

## The Problem Solved

In modern autonomous finance and AI agent ledgers:

1. **Phantom Payments**: Systems record ledger writes that never actually cleared the underlying settlement network.
2. **Duplicate Disbursals**: Network retries, agent loops, or human double-clicks submit the same invoice payment multiple times, causing repeated money movement.
3. **Premature Ledger State**: Ledgers mark records as `PAID` upon submission (`SUBMITTED`), before settlement confirmation arrives.

---

## Architectural Guarantees

```text
                      ┌───────────────────┐
                      │   SolidInvoice    │
                      │ Invoice INV-001   │
                      │ Status: UNPAID    │
                      └─────────┬─────────┘
                                │ (1) Request Settlement (10x duplicate-safe)
                                ▼
                 ┌────────────────────────────┐
                 │     Settlement Bridge      │
                 │        Laravel 12          │
                 │                            │
                 │  - Idempotency (SHA-256)   │
                 │  - State Machine           │
                 │  - Append-Only Audit Trail │
                 │  - Reconciliation Engine   │
                 └──────────────┬─────────────┘
                                │ (2) submit
                                ▼
                       SettlementProvider
                       ┌────────┴────────┐
                       ▼                 ▼
             FakeSettlementProvider  ArcSettlementProvider
                                         │ (3) USDC transfer
                                         ▼
                                  Circle / Arc Testnet
                                         │
                                         ▼ (4) Webhook confirmation
                                 Settlement Bridge
                                         │
                                         │ (5) Verified external receipt
                                         ▼
                                   SolidInvoice
                                     → PAID ✓
```

1. **Deterministic Idempotency**: SHA-256 payload canonicalization (`source|invoice_id|recipient|amount|currency`) combined with database-level `UNIQUE(idempotency_key)` and `UNIQUE(source, source_invoice_id)` constraints guarantees $N$ identical payment requests generate exactly 1 settlement record. A deterministic UUIDv5 provider key ensures all network retries submit identical idempotency headers to the settlement provider.
2. **Strict State Machine**: Explicit transitions (`CREATED` → `SUBMITTED` → `CONFIRMED` / `FAILED`, plus `SUBMISSION_UNKNOWN` for recoverable timeouts). The engine strictly enforces the invariant: `SUBMITTED != PAID`.
3. **External Witness Evidence**: SolidInvoice is marked `PAID` through supported API paths only after the external transaction details (recipient, amount, currency, transaction ID, tx_hash) are independently verified against the locked settlement record.
4. **Append-Only Audit Trail**: Every lifecycle transition (`SETTLEMENT_CREATED`, `PAYMENT_SUBMITTED`, `DUPLICATE_REQUEST_RECEIVED`, `SETTLEMENT_REQUEST_REJECTED`, `PROVIDER_TIMEOUT`, `SETTLEMENT_CONFIRMED`, `RECONCILIATION_QUEUED`, `INVOICE_RECONCILED`) is permanently journaled in `settlement_events`.
5. **Pluggable Architecture**: Zero-dependency `FakeSettlementProvider` for instant offline testing and demos, plus `ArcSettlementProvider` communicating with Circle Developer-Controlled Wallets on Arc Testnet.
6. **Crash & Ambiguity Recovery**: Provider timeouts do not mark settlements failed; they transition to `SUBMISSION_UNKNOWN` and can be safely retried via `php artisan settlements:recover-unknown` using the same provider idempotency key.

---

## Quickstart

### Prerequisites

- PHP 8.3+ with `bcmath`, `pdo_sqlite` or `pdo_pgsql`
- Composer 2+
- Node.js 20+

### 1. Installation

```bash
# Clone the repository
git clone https://github.com/Tum-404/arc-settlement-bridge.git
cd arc-settlement-bridge

# Install backend and frontend dependencies
composer install
npm install

# Setup environment & key
cp .env.example .env
php artisan key:generate

# Run migrations
php artisan migrate

# Build frontend assets
npm run build
```

### 2. Start Local Development Server

```bash
php artisan serve
```

Open [http://localhost:8000](http://localhost:8000) to access the interactive dashboard!

### 3. Docker Compose (Optional)

To run the full stack with PostgreSQL 17 and SolidInvoice in Docker:

```bash
docker compose up -d
```

---

## Running the Automated Test Suite

The test suite contains 20 automated tests (159 assertions) covering concurrency races, idempotency spam, state transitions, webhook verification, safety hardening, and full end-to-end reconciliation:

```bash
# Run Pest test suite (all 20 tests pass)
php artisan test

# Check static typing with PHPStan (Level max/strict, 0 errors)
vendor/bin/phpstan analyse --memory-limit=1G

# Check code formatting with Laravel Pint
vendor/bin/pint --test

# Type-check Vue frontend
npm run types:check
```

### Critical Test Highlights

- `tests/Feature/Settlement/IdempotencyTest.php`:
    - `duplicate request creates exactly one settlement and returns existing resource`
    - `ten identical payment requests result in exactly one database settlement`
    - `amount normalization guarantees identical key across equivalent decimal string formats`
    - `concurrent duplicate requests submit to provider only once`
- `tests/Feature/Settlement/SafetyHardeningTest.php`:
    - `a different payment coordinate for an existing invoice is rejected and audited`
    - `incomplete settlement evidence cannot confirm a transaction`
    - `unsigned webhook callbacks are rejected when local unsigned mode is disabled`
    - `a provider connection failure remains recoverable instead of being marked failed`
- `tests/Feature/Settlement/StateMachineTest.php`:
    - `submitted settlement does not mark invoice paid (SUBMITTED != PAID)`
    - `reconciliation cannot occur on unconfirmed settlement`
    - `failed settlement records failure details and leaves invoice unpaid`
- `tests/Feature/Settlement/WebhookTest.php`:
    - `replayed webhook delivery is safe and idempotent`
    - `mismatched webhook amount rejects confirmation and prevents reconciliation`
    - `mismatched webhook recipient rejects confirmation and prevents reconciliation`
- `tests/Feature/Settlement/ReconciliationTest.php`:
    - `confirmed settlement marks invoice paid with verifiable receipt evidence`
- `tests/Feature/Settlement/FullEndToEndTest.php`:
    - `full end-to-end lifecycle: 10 identical requests -> 1 database row -> 1 provider transfer -> webhook confirmed -> 1 invoice reconciliation`

---

## Operational Commands

| Command                                   | Purpose                                                                                                                               |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `php artisan settlements:recover-unknown` | Safely checks and retries settlements in ambiguous submission states (`SUBMISSION_UNKNOWN`) without risking duplicate money movement. |
| `php artisan test`                        | Runs the full Pest test suite verifying all invariants.                                                                               |
| `php artisan migrate`                     | Executes database migrations including unique constraints and audit tables.                                                           |

---

## API Endpoints

| Method | Endpoint                                 | Description                                                                                      |
| ------ | ---------------------------------------- | ------------------------------------------------------------------------------------------------ |
| `GET`  | `/api/settlements`                       | List all settlements and audit events                                                            |
| `POST` | `/api/settlements`                       | Submit an idempotent payment request (`source`, `invoice_id`, `recipient`, `amount`, `currency`) |
| `GET`  | `/api/settlements/{id}`                  | Retrieve settlement details and complete event timeline                                          |
| `POST` | `/api/settlements/{id}/simulate-confirm` | Simulate external confirmation and trigger invoice reconciliation                                |
| `POST` | `/api/webhooks/arc`                      | Webhook endpoint for Circle / Arc Testnet notifications                                          |
| `POST` | `/demo/reset`                            | Reset demo state for interactive presentations                                                   |

`simulate-confirm` and `demo/reset` are demo-only endpoints. They must not be
registered in a public production deployment.

---

## Configuration

In `.env`:

```env
# Provider Mode ("fake" for local zero-dependency testing, "arc" for Arc Testnet)
SETTLEMENT_PROVIDER=fake

# Invoicing Gateway ("fake" for local mock, "solidinvoice" for live SolidInvoice)
INVOICE_GATEWAY=fake

# Circle / Arc Testnet Settings
CIRCLE_API_KEY=
CIRCLE_ENTITY_SECRET=
CIRCLE_BLOCKCHAIN=ARC-TESTNET
ARC_WALLET_ID=
ARC_WALLET_ADDRESS=
ARC_USDC_CONTRACT_ADDRESS=
ARC_WEBHOOK_SECRET=

# SolidInvoice API Settings
SOLIDINVOICE_URL=http://localhost:8080
SOLIDINVOICE_TOKEN=
```

---

## License

MIT
