# Project Context

## Why this project exists

The project started from an invite-only virtual hackathon focused on next-generation accounting and ERP tools using stablecoins. The organizer explicitly welcomed builders who were new to crypto/stablecoins and asked for useful tools for founders and businesses.

The selected direction is a **Settlement Bridge** rather than a new ERP, a new accounting system, or a large AI agent.

## Problem statement

Traditional invoicing software can record that a payment happened without independently proving that money actually moved. In agent-driven workflows, this becomes more dangerous because retries, timeouts, or duplicate actions can cause:

- phantom payments
- duplicate payments
- incorrect reconciliation
- audit ambiguity

The project addresses this with:

- deterministic idempotency
- proof/confirmation of settlement
- controlled state transitions
- append-only audit events
- integration through existing application write paths

## Chosen first integration

SolidInvoice is the first target because it is PHP/Symfony and fits the developer's background.

The broader architecture should avoid hard-coding the system around SolidInvoice so that adapters for Invoice Ninja, ERPNext, or Odoo can be added later.

## Developer background relevant to the design

The developer has experience solving reliability and batch-processing problems in constrained legacy PHP environments.

A representative past project was a fuzzy global search feature built in PHP 5.3 + MariaDB with:

- configurable Levenshtein matching
- token/name re-ordering
- 40,000+ keyword batch input
- no native DB Levenshtein
- no queue workers
- restricted server access
- chunked Ajax processing
- temporary intermediate state
- deterministic signatures/caching
- resume/retry behavior
- duplicate prevention
- final deduplication and ranking

This background maps naturally to the settlement problem:

- request signature → idempotency key
- retry/resume → safe financial retry
- duplicate prevention → exactly-once payment intent
- finalization → reconciliation after external confirmation

## Architecture

```text
SolidInvoice
    |
    | request payment
    v
Settlement Bridge (Laravel/PHP)
    - validate request
    - generate idempotency key
    - create/reuse settlement
    - submit via SettlementProvider
    - store provider transaction ID/hash
    - wait for confirmation
    - reconcile invoice
    - append audit events
    |
    v
Arc / stablecoin settlement
```

Provider abstraction:

```text
SettlementProvider
    |- FakeSettlementProvider
    `- ArcSettlementProvider
```

Future source-system abstraction:

```text
Invoice System
    |- SolidInvoice Adapter
    |- Invoice Ninja Adapter (future)
    |- ERPNext Adapter (future)
    `- Odoo Adapter (future)

           |
           v
     Settlement Core
           |
           v
   SettlementProvider
```

## Key design decision: submission is not settlement

Incorrect:

```text
send payment
mark invoice paid
```

Correct:

```text
create settlement
→ submit transaction
→ status SUBMITTED
→ wait for external confirmation
→ status CONFIRMED
→ mark invoice PAID
```

If the transaction fails:

```text
SUBMITTED → FAILED
invoice remains UNPAID
```

## Key design decision: idempotency first

Example deterministic key:

```text
SHA256(source + invoice_id + recipient + amount + currency)
```

Enforce it with a UNIQUE DB constraint.

Expected behavior:

```text
Request #1 → created
Request #2 → existing settlement
Request #3 → existing settlement
...
Blockchain/provider transaction count = 1
```

## Key design decision: auditability

Suggested events:

- SETTLEMENT_CREATED
- PAYMENT_SUBMITTED
- DUPLICATE_REQUEST_REJECTED
- SETTLEMENT_CONFIRMED
- SETTLEMENT_FAILED
- INVOICE_MARKED_PAID

Keep the event log append-only.

## AI stance

AI should not directly become the money-release condition.

Preferred optional flow:

```text
AI recommends
→ human authorizes
→ Settlement Bridge enforces
→ Arc settles
→ invoice/ledger records
```

## Hackathon definition of done

```text
Invoice UNPAID
→ settlement requested
→ duplicate-safe submission
→ Arc settlement confirmed
→ invoice PAID
→ auditable record
```

Everything beyond this is optional.

## Productization principles

The project should look like a reusable open-source component rather than a one-off hack:

- Docker Compose
- `.env.example`
- README
- architecture diagram
- API docs
- failure scenarios
- security assumptions
- tests for critical flows
- fake provider for local development
- real provider adapter isolated behind interface
