# Safe Arc MVP Remediation

This implementation hardens the settlement bridge for an Arc testnet and SolidInvoice MVP.

- Persist a UUIDv5 provider idempotency key and reuse it for every provider retry.
- Enforce one immutable settlement per source invoice and validate requests against the invoice gateway.
- Keep ambiguous provider timeouts in `submission_unknown` and recover them with `settlements:recover-unknown`.
- Require complete confirmation evidence, lock confirmation/reconciliation records, and persist reconciliation state separately from confirmation.
- Fail closed for unsigned Arc callbacks outside explicitly local/testing configuration and retry failed webhook records safely.
- Use the settlement idempotency key for invoice reconciliation calls and the SolidInvoice payment reference.

The automated feature tests cover duplicate requests, confirmation replay, reconciliation, failures, and state transitions. Follow-up operational work should run the recovery command on a schedule and configure a real Arc webhook secret before enabling the Arc provider.
