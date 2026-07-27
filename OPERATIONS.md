# WorkConnect Operations Runbook

## Production architecture

- Run the PHP application as one or more stateless containers behind HTTPS.
- Use PostgreSQL through `DATABASE_URL`; do not use SQLite in production.
- Store uploads in S3-compatible object storage through `STORAGE_DRIVER=s3`.
- Send transactional email through Resend and process the database outbox on a schedule.
- Keep Stripe webhook delivery enabled at `/?page=stripe-webhook`.

The Render blueprint in `render.yaml` uses paid Starter services because production web, cron, and backup jobs must not depend on a sleeping service or an ephemeral filesystem. PostgreSQL and object storage are mandatory for durable data.

## First deployment

1. Create PostgreSQL, an S3-compatible private bucket, a Resend sender, and a Stripe account.
2. Generate a unique encryption key:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

3. Set every variable marked `sync: false` in `render.yaml` for the web service and each cron service. Never reuse local or test secrets.
4. Initialize an empty PostgreSQL database:

```bash
php scripts/database-migrate.php
```

5. If importing the current local data, run this once against an empty target:

```bash
DATABASE_URL='postgresql://...' php scripts/database-migrate.php --import-sqlite
```

6. Disable demo and maintenance modes, enable MFA for every active admin, and run:

```bash
php scripts/production-check.php
php scripts/reconcile-finance.php
```

7. Verify `/?page=health-live` and `/?page=health-ready` over the public HTTPS URL.
8. Run each Render cron manually once, then verify `php scripts/check-operations.php` reports success.

## Admin provisioning and demo retirement

Administrator accounts are never available from the public registration form. Register a normal active account first, then promote it from a trusted server shell:

```bash
php scripts/promote-admin.php --email='you@example.com' --admin-role=owner --yes
```

Sign out, sign in again, and enable MFA at `?page=admin-security#mfa` before granting access to production tools.

To remove demo records while retaining the service catalogue, first create and approve a real seller account. The retained services are reassigned to that seller, then all demo users, orders, messages, payments, coupons, wallets, and related activity are removed in one transaction:

```bash
php scripts/retire-demo-data.php --service-owner-email='seller@example.com' --yes
```

Create a backup and run `php scripts/reconcile-finance.php` immediately before and after the retirement command.

## Scheduled jobs

Run these jobs from a trusted worker with the same production environment:

```cron
*/2 * * * * cd /var/www/html && php scripts/process-outbox.php 50
15 2 * * * cd /var/www/html && scripts/backup-db.sh
30 2 * * * cd /var/www/html && php scripts/reconcile-finance.php
```

`render.yaml` provisions the same three jobs. The backup job requires `BACKUP_OFFSITE_REQUIRED=1`; it uploads both the dump and its SHA-256 checksum to the private S3-compatible bucket before it records success. A backup that exists only on the application host is not a disaster-recovery backup.

## Backup and restore

Create an integrity-checked backup and SHA-256 checksum:

```bash
scripts/backup-db.sh
```

Restore only during a maintenance window. The restore script validates the checksum and creates a safety backup of the current target first:

```bash
DATABASE_URL='postgresql://...' scripts/restore-db.sh storage/private/backups/workconnect-YYYYMMDD-HHMMSS.dump
```

After every restore:

```bash
php scripts/reconcile-finance.php
php scripts/production-check.php
php scripts/check-operations.php
```

Then smoke-test login, orders, messages, disputes, payouts, uploads, email, and one Stripe test transaction before reopening traffic.

## Monitoring

Alert on:

- Non-200 readiness checks for more than five minutes.
- Ledger reconciliation failures.
- Stripe webhook retries or stale `payment_requests.status='processing'`.
- Outbox failures or a growing pending queue.
- Repeated login, MFA, password reset, payout, or dispute rate-limit events.
- Disk growth in logs/backups and unusual database connection latency.
- A stale or failed `outbox`, `reconciliation`, or `backup` job from `php scripts/check-operations.php`.

Application logs include a request ID. Use the same ID in `security_logs` to trace a user-visible error without exposing internal exception details.

## Incident response

1. Enable maintenance mode if money, identity data, or order state may be at risk.
2. Preserve logs, database snapshots, webhook event IDs, and affected request IDs.
3. Rotate exposed credentials in the provider first, then update the deployment.
4. Reconcile the ledger before manually changing wallet, escrow, payout, or refund records.
5. Notify affected users according to applicable privacy and breach-notification rules.
6. Document the timeline, root cause, impact, remediation, and prevention.

Never repair financial history by deleting ledger rows. Post a reviewed compensating transaction with a unique reference.

## CI quality gate

`.github/workflows/quality.yml` runs on every push and pull request. It validates PHP and shell syntax, SQL portability and encryption helpers, migration of scheduled-job records, then starts the web application and runs smoke, security, role-route, order-lifecycle, password-reset, realtime, and dark-mode regression tests.

Protect the production branch so a pull request cannot merge until this workflow passes. The workflow intentionally uses test credentials and local SQLite only; Stripe live keys, provider webhooks, and PostgreSQL must still be verified in staging before production release.

## Rollback

Deploy the previous known-good image while keeping the current database unless the release included an incompatible schema change. Database migrations are additive; do not run destructive rollback SQL.

If data restoration is unavoidable, enable maintenance mode, take a safety backup, restore the verified snapshot, rerun migration, reconcile finance, and complete smoke tests before reopening traffic.

## Key rotation

- Rotate Stripe, Resend, PostgreSQL, and S3 credentials independently through their providers.
- Rotating `APP_ENCRYPTION_KEY` requires a controlled decrypt-and-reencrypt migration for encrypted MFA secrets and payout destinations. Do not replace it directly while encrypted records exist.
- Revoke all sessions after a suspected authentication-secret compromise.
