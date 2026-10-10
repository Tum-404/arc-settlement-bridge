# Codebase Audit & Architectural Critique

**Project**: Arc Settlement Bridge  
**Date**: October 2026  
**Scope**: Invariants, Reliability, Production-Readiness, and Code Quality Review against [AGENTS.md](AGENTS.md)

---

## 1. Executive Summary

While the project successfully demonstrates the core hackathon invariant (**"an invoice is paid exactly once, and is marked PAID only after settlement is confirmed"**) with passing Pest tests and strict typing, a deeper technical analysis reveals several **critical runtime bugs, architectural inconsistencies, and dead code leftovers** that should be resolved before real production or testnet deployment.

---

## 2. Critical Bugs & Runtime Failures (Must Fix)

### 🔴 Bug 1: PostgreSQL Crash on `/demo/reset` (`DashboardController::resetDemo`)

- **Location**: [`app/Http/Controllers/DashboardController.php`](app/Http/Controllers/DashboardController.php#L47)
- **Problem**:
  The endpoint executes `Settlement::truncate()`. In migration [`2026_09_28_000002_create_settlement_events_table.php`](database/migrations/2026_09_28_000002_create_settlement_events_table.php), `settlement_events` has a foreign key to `settlements` with `restrictOnDelete()`.
  In PostgreSQL, executing `TRUNCATE settlements;` triggers a fatal SQL error:
    ```sql
    ERROR: cannot truncate a table referenced in a foreign key constraint
    DETAIL: Table "settlement_events" references "settlements".
    HINT: Truncate table "settlement_events" at the same time, or use TRUNCATE ... CASCADE.
    ```
- **Why tests missed it**: Pest tests run against SQLite in-memory, which does not enforce this foreign key restriction on truncate.
- **Fix**: Safely delete child audit records before parent records:
    ```php
    SettlementEvent::query()->delete();
    Settlement::query()->delete();
    ProviderWebhookEvent::query()->delete();
    ```

---

### 🔴 Bug 2: Real Arc/Circle Integration Fails (`entitySecretCiphertext` Missing)

- **Location**: [`app/Infrastructure/Arc/CircleClient.php`](app/Infrastructure/Arc/CircleClient.php#L37-L53)
- **Problem**:
  Circle's Developer-Controlled Wallets API strictly requires an `entitySecretCiphertext` parameter for all write transactions (`/v1/w3s/developer/transactions/transfer`). This requires:
    1. Fetching Circle's developer public RSA key (`GET /v1/w3s/developer/publicKey`).
    2. Encrypting the 32-byte hex `CIRCLE_ENTITY_SECRET` using RSA-OAEP.
    3. Passing the ciphertext with each transfer.
       Although `CIRCLE_ENTITY_SECRET` exists in `.env.example`, `CircleClient.php` never encrypts or sends it.
- **Impact**: Toggling `SETTLEMENT_PROVIDER=arc` to connect to real Arc Testnet will immediately fail with `HTTP 400 (entitySecretCiphertext is required)`.

---

### 🔴 Bug 3: Database Collision in `docker-compose.local.yml`

- **Location**: [`docker-compose.local.yml`](docker-compose.local.yml#L18-L74)
- **Problem**:
  Both the Laravel Bridge and SolidInvoice are configured to use the **exact same PostgreSQL database**: `settlement`:
    ```yaml
    # bridge:
    DB_DATABASE: settlement

    # solidinvoice:
    SOLIDINVOICE_DATABASE_URL: postgresql://settlement_bridge:settlement_local_password@db:5432/settlement?serverVersion=17&charset=utf8
    ```
    Laravel migrations have already created tables: `users`, `migrations`, `sessions`, `cache`, etc.
    When SolidInvoice completes its setup wizard and runs Doctrine migrations, it will attempt to create its own `users` table and migration tracking in the public schema, leading to table conflicts.
- **Fix**: Isolate SolidInvoice into its own database (e.g. `solidinvoice`) on the Postgres instance.

---

## 3. Architectural & Design Inconsistencies

### ⚠️ Issue 1: "Fake" Asynchrony (`RECONCILIATION_QUEUED` is synchronous)

- **Location**: [`app/Application/Settlement/Commands/ConfirmSettlement.php`](app/Application/Settlement/Commands/ConfirmSettlement.php#L101-L112)
- **Problem**:
  The system logs an event named `RECONCILIATION_QUEUED`, implying background asynchronous dispatch. In reality, it immediately executes `ReconcileSettlement` synchronously within the incoming webhook controller process (`/api/webhooks/arc`).
  If SolidInvoice is slow or down (timeout is 15s), the Circle webhook caller hangs and may encounter an HTTP timeout.
- **Fix**: Either dispatch a true queued job (`ReconcileSettlementJob::dispatch($settlement)`), or rename the event to reflect synchronous reconciliation (`RECONCILIATION_INITIATED`).

---

### ⚠️ Issue 2: Dead Infrastructure Bloat (Unused Queue Worker)

- **Location**: [`docker-compose.yml`](docker-compose.yml#L26-L38) & [`database/migrations/0001_01_01_000002_create_jobs_table.php`](database/migrations/0001_01_01_000002_create_jobs_table.php)
- **Problem**:
  The Docker Compose configuration provisions and runs a dedicated `queue` worker container (`php artisan queue:work`), and database migrations create job tables. However, **the application code does not contain a single queued job, event listener, or `dispatch()` call**.

---

### ⚠️ Issue 3: Poller Redundant Duplicate Logging on Confirmed Invoices

- **Location**: [`app/Application/Settlement/Commands/PollSolidInvoiceInvoices.php`](app/Application/Settlement/Commands/PollSolidInvoiceInvoices.php#L63-L70)
- **Problem**:
  `hasActiveLifecycle()` only checks for:
  `[CREATED, SUBMITTED, SUBMISSION_UNKNOWN]`
  If an invoice has already been `CONFIRMED` by the bridge, but SolidInvoice's status update is still propagating or cached in the listing API, the poller re-submits every minute. Although the settlement idempotency key prevents double payment, it generates a stream of redundant `DUPLICATE_REQUEST_RECEIVED` audit log entries.
- **Fix**: Add `SettlementStatus::CONFIRMED->value` to `hasActiveLifecycle()`.

---

### ⚠️ Issue 4: Frontend UI Missing `submission_unknown`

- **Location**: [`resources/js/pages/SettlementDashboard.vue`](resources/js/pages/SettlementDashboard.vue#L30)
- **Problem**:
  The backend state machine supports `submission_unknown` for crash/timeout recovery. However, the frontend TypeScript interface only defines:
    ```typescript
    status: 'created' | 'submitted' | 'confirmed' | 'failed';
    ```
    The dashboard lacks UI badges or handling for settlements currently in `submission_unknown` state.

---

## 4. Redundant & Dead Code Leftovers

1. **`resources/js/pages/Welcome.vue`**:
    - A 38 KB boilerplate page left over from the default Laravel Inertia starter template. It is never routed or referenced anywhere in the application.
    - **Recommendation**: Delete the file.

2. **Unused Authentication & User Boilerplate**:
    - Files: [`app/Models/User.php`](app/Models/User.php), [`database/migrations/0001_01_01_000000_create_users_table.php`](database/migrations/0001_01_01_000000_create_users_table.php), [`database/factories/UserFactory.php`](database/factories/UserFactory.php).
    - The Settlement Bridge is a machine-to-machine API gateway and poller with no interactive user authentication or sessions. Keeping unused auth tables pollutes the schema.

3. **HMAC vs Asymmetric Webhook Signature Verification**:
    - [`CircleWebhookVerifier.php`](app/Infrastructure/Arc/CircleWebhookVerifier.php) implements symmetric HMAC (`hash_hmac`).
    - Production Circle webhooks use asymmetric cryptography or AWS SNS notification signing with public X.509 certificates. This should be explicitly marked as a mock verifier until full asymmetric verification is added.

4. **Missing Rate Limiting**:
    - Endpoints `/api/settlements` and `/api/webhooks/arc` in [`routes/api.php`](routes/api.php) do not have `throttle` middleware attached. An external loop can fire thousands of database transactions without restriction.

---

## 5. Remediation Priority

| Priority | Task                                             | File(s)                                                            |
| :------: | :----------------------------------------------- | :----------------------------------------------------------------- |
|  **P0**  | Fix PostgreSQL foreign key crash on demo reset   | `app/Http/Controllers/DashboardController.php`                     |
|  **P0**  | Add `CONFIRMED` status to invoice poller filter  | `app/Application/Settlement/Commands/PollSolidInvoiceInvoices.php` |
|  **P1**  | Separate SolidInvoice database in docker-compose | `docker-compose.local.yml`                                         |
|  **P1**  | Implement Circle entity secret encryption        | `app/Infrastructure/Arc/CircleClient.php`                          |
|  **P2**  | Delete dead `Welcome.vue` and unused boilerplate | `resources/js/pages/Welcome.vue`                                   |
|  **P2**  | Add `submission_unknown` badge to Dashboard UI   | `resources/js/pages/SettlementDashboard.vue`                       |
|  **P2**  | Add rate limiting to API routes                  | `routes/api.php`                                                   |
