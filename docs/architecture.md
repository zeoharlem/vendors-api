# Architecture and security notes

## Module dependency policy

1. Domain depends only on the language and domain primitives.
2. Application depends on domain contracts and orchestrates use cases.
3. Infrastructure implements repository and gateway interfaces.
4. Presentation depends on application use cases and maps HTTP to API contracts.
5. Shared code must remain small; do not turn it into a dumping ground.

## Multi-tenant / vendor isolation

- Derive the actor and vendor/store scope from the authenticated principal and authorization policy.
- Never use a client-provided `vendor_id` as proof of ownership.
- Apply tenant scope to reads, updates, deletes, exports, background jobs, and webhook processing.
- Add negative authorization tests: vendor A must not read or mutate vendor B's records.
- Consider PostgreSQL row-level security as defense in depth, not a substitute for application authorization.

## Money and checkout

- Represent money as integer minor units plus ISO currency; never use binary floating point.
- Use a ledger with immutable entries for financial movements.
- Payment provider webhooks must verify signatures, persist event IDs, reject replays, and be idempotent.
- Use idempotency keys for checkout, refunds, and other non-repeatable operations.
- Use explicit order/payment state machines and transactional outbox events.

## API conventions

- Keep routes versioned under `/api/v1`.
- Use a consistent success/error envelope.
- Use cursor pagination for large datasets; limit/offset is acceptable only for simple starter endpoints.
- Publish OpenAPI and maintain contract tests for client consumers.
- Use UTC timestamps in ISO 8601.
- Add correlation IDs and avoid logging credentials, tokens, payment secrets, or sensitive payloads.
