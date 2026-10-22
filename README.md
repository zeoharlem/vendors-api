# Phalcon Multivendor API — Clean Architecture Starter

A modular REST API starter for a multivendor marketplace using Phalcon 5, PHP, PostgreSQL, and Clean Architecture.

> Version note (2026-10-21): the official Phalcon C-extension release page lists 5.22.1 as the latest release, not 5.23.0. The dependency is intentionally pinned to `~5.22.1` until 5.23.0 is available and verified. Change the constraint only after validating the target release and PHP runtime.

## Architecture

- `app/Modules/*/Domain`: business entities, rules, repository contracts
- `app/Modules/*/Application`: use cases and application contracts
- `app/Modules/*/Infrastructure`: database adapters and external services
- `app/Modules/*/Presentation`: HTTP controllers and DTOs
- `app/Shared`: cross-module primitives and HTTP response helpers
- `app/Bootstrap`: DI registration and route registration

Keep domain/application code independent of Phalcon HTTP classes. Controllers should translate HTTP input into use-case calls and map results to JSON.

## Included in this scaffold

- Phalcon bootstrap and versioned `/api/v1` routes
- Health endpoint
- Consistent JSON response helper
- Example Catalog module with product repository contract and use case
- PostgreSQL connection registration
- Environment-based configuration
- Docker Compose development services
- Production-oriented security checklist and extension points

This is a **starter scaffold**, not a claim that every production concern (JWT, migrations, rate limiting, outbox, payment/escrow reconciliation, etc.) is already implemented. Complete and test those before production deployment.

## Requirements

- PHP runtime supported by your chosen Phalcon 5 build
- Phalcon C extension (verify with `php -m | grep -i phalcon`)
- Composer 2
- PostgreSQL 15+
- Docker / Docker Compose (optional)

## Local setup

1. Install a compatible PHP runtime and the Phalcon 5 C extension.
2. Copy `.env.example` to `.env` and set non-default secrets.
3. Install dependencies: `composer install`
4. Start PostgreSQL: `docker compose up -d db`
5. Configure the web server document root to `public/`.
6. Run the app with PHP-FPM/Nginx in production. For a quick local smoke test, use a compatible PHP built-in server and route requests through `public/index.php`.

## Example endpoints

- `GET /api/v1/health`
- `GET /api/v1/products` (example endpoint returns an empty list until a database adapter/use case is wired)
- `GET /api/v1/products/{id}` (example application path; persistence adapter remains an implementation task)

## Production checklist

- [ ] Pin PHP, Phalcon, Composer dependencies, and container image digests.
- [ ] Add migrations and deterministic deployment workflow.
- [ ] Implement JWT validation, refresh-token rotation, revocation, and key rotation.
- [ ] Enforce tenant/vendor ownership in every query and use case; never trust `vendor_id` supplied by a client.
- [ ] Add authorization policies and least-privilege roles.
- [ ] Add request size limits, rate limiting, CORS allowlist, security headers, and timeouts.
- [ ] Use parameterized queries and strict DTO validation.
- [ ] Add idempotency keys for checkout/payment/refund endpoints.
- [ ] Use a transactional outbox for reliable domain-event publication.
- [ ] Keep a double-entry ledger for money movement; do not treat a payment-provider status as the ledger.
- [ ] Add payment webhook signature verification and replay protection.
- [ ] Add structured logs, correlation IDs, metrics, tracing, and alerting.
- [ ] Add unit, integration, contract, and authorization-isolation tests.
- [ ] Back up PostgreSQL, test restores, and define retention policies.
- [ ] Store secrets in a secret manager, not in source control.
- [ ] Run dependency, container, and static-analysis scans in CI.

## Suggested next implementation order

1. Identity + access tokens + RBAC
2. Tenant/store ownership model and authorization tests
3. Catalog + inventory
4. Cart + checkout + order state machine
5. Payment intents + idempotent webhooks
6. Ledger/escrow + reconciliation
7. Logistics + shipment tracking
8. OpenAPI contract, rate limits, observability, CI/CD
