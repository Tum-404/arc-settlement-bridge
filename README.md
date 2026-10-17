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

## Quickstart: one-command local stack

The fastest way to run the Bridge with SolidInvoice is Docker Desktop 4+:

```bash
git clone https://github.com/Tum-404/arc-settlement-bridge.git
cd arc-settlement-bridge
docker compose -f docker-compose.local.yml up --build
```

This starts the Bridge at [http://localhost:8000](http://localhost:8000),
SolidInvoice at [http://localhost:8765](http://localhost:8765), PostgreSQL,
and a poller that runs every minute. The Bridge database, SolidInvoice setup,
and invoices persist in Docker volumes.

On first use, complete SolidInvoice's normal browser setup. Its PostgreSQL
connection is already configured by the local stack—do not select SQLite or
enter database credentials in the wizard. Create an API token and give the
invoice recipient a `crypto_address` custom field. Restart the stack with that
token; this is the only Bridge integration configuration:

```bash
SOLIDINVOICE_TOKEN=your-token docker compose -f docker-compose.local.yml up -d
```

In PowerShell:

```powershell
$env:SOLIDINVOICE_TOKEN = 'your-token'
docker compose -f docker-compose.local.yml up -d
```

The local stack intentionally uses `FakeSettlementProvider`, so users can prove
the end-to-end workflow without credentials or test funds. To stop it, use
`docker compose -f docker-compose.local.yml down`. Add `-v` only when you
intentionally want to erase the local Bridge and SolidInvoice data.

If the first-run database credentials were entered incorrectly, reset the
local-only volumes and start again:

```bash
docker compose -f docker-compose.local.yml down -v
docker compose -f docker-compose.local.yml up --build
```

`down -v` permanently deletes local invoices and Bridge audit data; do not use
it for a stack that contains data you need to keep.

## Manual installation

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

# Create the first dashboard operator. The command prompts for the password.
php artisan users:create-admin operator@example.com --name="Settlement Operator"

# Build frontend assets
npm run build
```

### 2. Start Local Development Server

```bash
php artisan serve
```

Open [http://localhost:8000](http://localhost:8000) to access the interactive dashboard!

### Operator access

The dashboard, recipient/settlement API, demo controls, and
[Log Viewer](http://localhost:8000/log-viewer) require an authenticated local
operator session. Provision an operator after every fresh environment setup:

```bash
php artisan users:create-admin operator@example.com --name="Settlement Operator"
```

The command requests a password interactively and rejects passwords shorter
than 12 characters. It does not seed a shared default account. To deliberately
reset an existing operator account, pass `--force`. Avoid `--password` in a
shared shell or CI log because command-line arguments can be retained in shell
history.

Circle and SolidInvoice webhook endpoints remain unauthenticated by session:
they are external machine-to-machine endpoints and enforce their configured
signature checks instead.

### 3. Production Docker Compose

To run the full stack with PostgreSQL 17 and SolidInvoice in Docker:

```bash
docker compose up -d
```

---

## Running the Automated Test Suite

The test suite currently has 63 passing tests with one PostgreSQL-only test skipped under the default SQLite configuration. It covers concurrency races, idempotency spam, state transitions, webhook verification, safety hardening, recipient controls, authentication, and full end-to-end reconciliation:

```bash
# Run the Pest test suite
php artisan test

# Check static typing with PHPStan (Level max/strict, 0 errors)
vendor/bin/phpstan analyse --memory-limit=1G

# Check code formatting with Laravel Pint
vendor/bin/pint --test

# Type-check Vue frontend
npm run types:check
```

### SolidInvoice polling (recommended integration)

SolidInvoice remains an untouched upstream application. Run the Bridge worker
on a cron schedule (or Laravel scheduler); it reads payable `pending` and
`overdue` invoices using the SolidInvoice REST API and ignores paid, cancelled,
and actively-tracked invoices:

```bash
php artisan settlements:poll-solidinvoice
```

Use `--limit=100` to cap an iteration. API connection failures are logged with
the configured `SOLIDINVOICE_POLL_BACKOFF_SECONDS` and return a non-zero exit
code so the process supervisor can retry without creating a duplicate payment.
The Bridge's unique idempotency key and provider idempotency key remain the
final duplicate-disbursement protection.

For a one-minute cron schedule:

```cron
* * * * * cd /path/to/arc-settlement-bridge && php artisan settlements:poll-solidinvoice >> /dev/null 2>&1
```

The older signed webhook endpoint is retained only for backwards compatibility;
the poller is the supported SolidInvoice integration and does not require a
Symfony subscriber, plugin, or database access in SolidInvoice.

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

| Command                                     | Purpose                                                                                                                               |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `php artisan settlements:recover-unknown`   | Safely checks and retries settlements in ambiguous submission states (`SUBMISSION_UNKNOWN`) without risking duplicate money movement. |
| `php artisan settlements:poll-solidinvoice` | Out-of-process poller for payable SolidInvoice invoices; no SolidInvoice source modification required.                                |
| `php artisan users:create-admin email`      | Interactively provisions an operator for the dashboard and Log Viewer.                                                                    |
| `php artisan test`                          | Runs the full Pest test suite verifying all invariants.                                                                               |
| `php artisan migrate`                       | Executes database migrations including unique constraints and audit tables.                                                           |

---

## API Endpoints

| Method | Endpoint                                 | Description                                                                                      |
| ------ | ---------------------------------------- | ------------------------------------------------------------------------------------------------ |
| `GET`  | `/api/settlements`                       | List all settlements and audit events                                                            |
| `POST` | `/api/settlements`                       | Submit an idempotent payment request (`source`, `invoice_id`, `recipient`, `amount`, `currency`, optional `client_id`) |
| `GET`  | `/api/settlements/{id}`                  | Retrieve settlement details and complete event timeline                                          |
| `POST` | `/api/settlements/{id}/simulate-confirm` | Simulate external confirmation and trigger invoice reconciliation                                |
| `POST` | `/api/webhooks/arc`                      | Webhook endpoint for Circle / Arc Testnet notifications                                          |
| `POST` | `/demo/reset`                            | Reset demo state for interactive presentations                                                   |

All dashboard-facing endpoints above require an authenticated operator session.
Browser requests to protected mutating endpoints also require a CSRF token.
`/api/webhooks/arc` and `/api/webhooks/solidinvoice` remain session-free so
Circle and SolidInvoice can deliver signed callbacks.

`simulate-confirm` and `demo/reset` are demo-only endpoints. They return `404`
unless `SETTLEMENT_DEMO_MODE=true` is explicitly configured; keep that value
false in every non-demo deployment.

For the automatic SolidInvoice-to-Bridge trigger contract, including HMAC
signing and retry behavior, see [docs/solidinvoice-trigger.md](docs/solidinvoice-trigger.md).

---

## Configuration

In `.env`:

```env
# Provider Mode ("fake" for local zero-dependency testing, "arc" for Arc Testnet)
SETTLEMENT_PROVIDER=fake

# Invoicing Gateway ("fake" for local mock, "solidinvoice" for live SolidInvoice)
INVOICE_GATEWAY=fake

# Enables simulated confirmation and demo reset routes. Keep false outside demos.
SETTLEMENT_DEMO_MODE=false

# Circle / Arc Testnet Settings
CIRCLE_API_KEY=
CIRCLE_ENTITY_SECRET=
CIRCLE_BLOCKCHAIN=ARC-TESTNET
ARC_WALLET_ID=
ARC_WALLET_ADDRESS=
ARC_USDC_CONTRACT_ADDRESS=
ARC_TOKEN_ID=
ARC_WEBHOOK_SECRET=

# SolidInvoice API Settings
SOLIDINVOICE_URL=http://localhost:8080
SOLIDINVOICE_TOKEN=
SOLIDINVOICE_POLL_LIMIT=100
SOLIDINVOICE_POLL_BACKOFF_SECONDS=60
```

---

## License

MIT
