# WorkConnect: Phase 2-8 Execution Plan

This plan is the production path after Phase 1 has retired demo activity and assigned the retained service catalogue to a real active seller. Do not accept real money before every Phase 2-4 gate is complete.

## Phase 2: Staging and deployment foundation

Implemented in the repository:

- `render.yaml` defines a paid web service, PostgreSQL migration before deploy, and separate cron services.
- `.env.example` and `scripts/production-check.php` require HTTPS, PostgreSQL TLS, Resend, S3-compatible storage, live Stripe keys, and off-site backups.
- `scripts/database-migrate.php` initializes PostgreSQL and can make a one-time SQLite import.

Owner action:

1. Create a private Git remote, a `staging` branch, and a production branch protected by CI.
2. Create separate staging and production projects/accounts for PostgreSQL, object storage, Resend, Stripe, and Render.
3. Configure all `sync: false` values in Render. Never send a secret in chat or commit it to `.env`.
4. Deploy staging, run `php scripts/database-migrate.php`, then import only an approved backup copy if data is needed.

Exit gate: public staging URL uses HTTPS; `health-live`, `health-ready`, `production-check`, and a manual deployment rollback all work.

## Phase 3: Payments and financial controls

Implemented in the repository:

- Stripe Checkout uses idempotency keys and signed webhook verification.
- Provider events are claimed once, payment requests expire safely, integer-satang ledger entries reconcile, and refunds are covered by automated lifecycle tests.

Owner action:

1. Configure Stripe test-mode webhook for the staging `/?page=stripe-webhook` URL.
2. Test PromptPay success, expiration, duplicate webhook delivery, refund, interrupted checkout, and incorrect amount cases.
3. Configure Stripe live webhook only after the staging matrix passes and the finance owner approves it.

Exit gate: `scripts/reconcile-finance.php` passes before and after every payment test, and webhook delivery is visible in Stripe without retries.

## Phase 4: Security and operations

Implemented in the repository:

- MFA is required for every active production administrator.
- Outbox, reconciliation, and backup jobs record their last result in `job_runs`.
- The backup command creates a checksum and, in production, uploads the backup and checksum to private object storage before recording success.
- `scripts/check-operations.php` detects missing, failed, or stale job runs.

Owner action:

1. Promote the named Owner account with `scripts/promote-admin.php`, then enable MFA before the first production login.
2. Run every scheduled job manually once in staging and production.
3. Perform a restore drill to a blank PostgreSQL database and run reconciliation afterward.
4. Choose a malware-scanning provider or quarantine service before allowing unrestricted document uploads.
5. Have legal review PDPA consent, retention, refund, payout, and incident-notification policies.

Exit gate: restore drill succeeds, `check-operations` passes for 24 hours, no admin lacks MFA, and off-site backup access is limited to operations staff.

## Phase 5: Engineering quality

Implemented in the repository:

- GitHub Actions quality gate checks PHP, shell scripts, migration behavior, encryption, and all current local integration suites.
- The test harness now runs under its declared zsh interpreter and has a regression test for schema migration revisions.

Owner action:

1. Require the `Quality gates` workflow before merge.
2. Add a PostgreSQL service to CI or a disposable staging database for schema and migration integration tests.
3. Refactor one bounded area at a time from the large action/page files into controller, service, repository, and view layers; preserve current behavior with tests first.

Exit gate: every production change has a reviewed pull request, passing CI, and a rollback note.

## Phase 6: Performance and reliability

Owner action:

1. Measure page response time, database query time, error rate, and checkout latency in staging with realistic traffic.
2. Add Redis-backed sessions and rate limits before running more than one web instance.
3. Add pagination and database query plans for marketplace search, messages, notifications, and audit logs before data volume grows.
4. Stream large object downloads and move CPU-heavy or scan work to a worker queue.

Exit gate: load testing meets the agreed concurrent-user target without ledger errors, session loss, or slow database queries.

## Phase 7: Closed beta

Owner action:

1. Invite 10-20 real users with a support contact and an explicit beta feedback channel.
2. Test keyboard navigation, screen reader labels, mobile layout, dark mode, Thai content, and dispute support with real workflows.
3. Review every support issue daily and classify it as P0, P1, or P2.

Exit gate: no unresolved P0/P1 issue, successful real-user journeys, and published support/refund expectations.

## Phase 8: Controlled launch

Owner action:

1. Start with limited users and daily finance reconciliation.
2. Monitor readiness, cron freshness, provider webhooks, mail failures, backup status, and security logs.
3. Keep an assigned incident owner, a maintenance-mode decision path, and a tested rollback/restore procedure.

Exit gate: stable beta period, successful restore drill, no financial drift, and an approved launch decision from product, engineering, and finance owners.
