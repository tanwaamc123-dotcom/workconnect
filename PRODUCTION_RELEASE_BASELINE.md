# WorkConnect Production Release Baseline

Date: 24 July 2026

## Scope

This baseline records the local release state before creating the staging environment. It is a repeatable gate, not an approval to accept real payments.

## Verified locally

- SQLite backup created at `storage/private/backups/phase0-20260724/workconnect-20260724-111534.sqlite`.
- PHP lint passed for application, scripts, and tests.
- JavaScript syntax check passed for `assets/js/app.js`.
- Shell syntax check passed for test and operational scripts.
- `git diff --check` passed.
- PostgreSQL URL parsing and portable SQL helper test passed.
- AES-256-GCM encryption and masking test passed.
- Smoke flow passed: Home, login, registration, dashboard, settings, and messages.
- Security regression passed: headers, protected files, forged-admin registration, and CSRF rejection.
- Customer, seller, and admin route suites passed.
- Order lifecycle passed: delivery, customer acceptance, review, cancellation, refund, and seller authorization.
- Password-reset flow passed: expiry, one-time token, and session revocation.
- Realtime stream released the PHP session lock in 0.058 seconds.
- Dark-mode routes passed for customer, seller, and administrator workspaces.
- Browser verification passed for the visible customer dashboard: no broken images, horizontal overflow, or console errors.
- Database checks passed: `PRAGMA integrity_check=ok`, no foreign-key violations, and no leftover smoke-test users.
- Finance reconciliation passed: 32 ledger entries, 13 ledger transactions, 8 users, and 14 orders.

## Test-harness repair

`tests/smoke.sh` was corrected during this phase. It used early-exiting pipes under `set -o pipefail`, which produced `SIGPIPE` after successfully loading a large HTML response. The script now reads response status and CSRF tokens without breaking the input pipe, and it removes its temporary smoke-test account on exit.

## Production gates still intentionally failing

The local environment correctly fails `php scripts/production-check.php` until the production services exist:

- `APP_ENV=production` and a public HTTPS `APP_URL`
- PostgreSQL connection through `DATABASE_URL`
- Stripe live publishable key, secret key, and webhook secret
- Resend transport, verified sender, and API key
- S3-compatible private object storage configuration

## Required before Phase 2

1. Decide and register the production domain.
2. Create provider-owned accounts for Render, Neon PostgreSQL, Cloudflare R2, Resend, and Stripe.
3. Keep staging and production credentials separate from local `.env` values.
4. Use a private Git remote and create an immutable commit from this verified working tree before deploying.
