# WorkConnect

WorkConnect is a role-based freelance services marketplace built with PHP 8, PostgreSQL/SQLite, CSS, and JavaScript.

## Run locally

Place the project inside XAMPP `htdocs`, start Apache, then open:

`http://localhost/WorkConnect/`

The SQLite database is created automatically at `storage/workconnect.sqlite`. Business data starts empty.

For PHP's built-in development server, use the included security router:

```bash
php -S 127.0.0.1:8098 dev-router.php
```

## PostgreSQL and online deployment

SQLite remains the zero-configuration local database. Production requires PostgreSQL through `DATABASE_URL`; Neon and Supabase connection URLs are supported.

1. Create an empty PostgreSQL database and copy its pooled connection URL.
2. Initialize PostgreSQL and transfer the current SQLite data:

```bash
DATABASE_URL='postgresql://user:password@host/database?sslmode=require' php scripts/database-migrate.php --import-sqlite
```

3. Verify the row counts printed by the migration command.
4. Set the same `DATABASE_URL` on the web host, together with `APP_ENV=production`, an HTTPS `APP_URL`, and the other values listed in `.env.example`.
5. Run `php scripts/production-check.php` in the production environment before accepting traffic.

The import refuses to write when the target already contains users. It checks SQLite integrity, transfers data inside a PostgreSQL transaction, resets ID sequences, and compares row counts before reporting success.

The included `Dockerfile` and `render.yaml` can deploy the app as a Render Docker service. Do not commit `.env`; enter all values marked `sync: false` in the host dashboard. Generate the encryption key with:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Render's free filesystem is ephemeral. Production uploads use the included S3-compatible private object-storage driver. See [OPERATIONS.md](OPERATIONS.md) for deployment, scheduled jobs, recovery, monitoring, and rollback.

## Demo lifecycle

The Home page contains a Demo Console:

- **Install demo data** creates isolated demo users, services, orders, messages, payments, coupons, and reviews.
- **Clear demo data** removes only records labeled as Demo.
- Real accounts, services, uploads, and notifications are preserved.
- Data created through a Demo service or order inherits the Demo label and is safely removed with that demo.

## Demo accounts

All demo accounts use the password `Demo1234!`.

| Role | Email |
| --- | --- |
| Customer | `customer@workconnect.test` |
| Seller | `seller@workconnect.test` |
| Administrator | `admin@workconnect.test` |

## Access model

- Home, About, Privacy, Terms, Help, Safety, Community, public service discovery, and authentication are available to guests.
- Customer, seller, and administrator workspaces require a valid server-side session.
- Role and capability guards separate customer, seller, support, moderation, finance, analyst, and owner actions.
- Administrators support TOTP MFA; production checks require MFA for every active administrator.

## Pages

### Public and authentication

- Home
- About, Privacy, Terms, Help Center, Safety, and Community
- Public service search and service details
- Login
- Register
- Password reset and MFA verification

### Authenticated shared pages

- About
- Services
- Service Detail
- Messages
- Notifications
- Profile
- Settings
- Disputes and privacy export/deletion requests

### Customer

- Dashboard
- Checkout
- Orders
- Saved services and wallet top up

### Seller

- Dashboard
- My Services
- Add/Edit Service
- Manage Orders
- Messages
- Earnings
- Analytics
- Payout requests
- Profile
- Settings

### Administrator

- Users
- Services
- Orders
- Message Audit
- Reports
- System Settings
- Disputes, payouts, finance reconciliation, audit logs, role management, moderation, coupons, broadcasts, and exports

## Implemented workflows

- Registration, login, logout, password reset, password hashing, persistent sessions, CSRF protection, capability authorization, TOTP MFA, rate limits, and audit logs
- Service discovery, filtering, service details, checkout, constrained coupons, Stripe-hosted PromptPay payments, wallet accounting, and order creation
- Customer cancellation/acceptance, seller delivery, revisions, disputes, refunds, reviews, and append-only order events
- Project conversations with protected image, PDF, and text attachments
- Notifications and email preferences
- Profile information and profile image uploads
- Seller service CRUD, moderation versioning, thumbnails, order delivery, earnings, payouts, and analytics
- Administrator user suspension, scoped roles, service moderation, order/dispute/payout oversight, message audit, reports, exports, and platform settings
- Integer-satang accounting with a double-entry ledger, idempotent provider events, and a reconciliation command
- S3-compatible private uploads, Resend outbox delivery, health endpoints, PostgreSQL migration, verified backups, and restore tooling

## Smoke tests

Run a quick local verification against the live app:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/WorkConnect
BASE_URL=http://127.0.0.1/WorkConnect bash tests/smoke.sh
```

Environment variables:

- `BASE_URL` sets the site URL to test, for example `http://127.0.0.1/WorkConnect`
- `SEED_DEMO=0` skips demo seeding if you want a non-mutating check
## Operations

Create an atomic SQLite backup with integrity check, SHA-256 checksum, and 14-day retention:

```bash
scripts/backup-db.sh
```

When `DATABASE_URL` is exported, the same command creates and verifies a PostgreSQL custom-format dump instead. Restore it with:

```bash
DATABASE_URL='postgresql://...' scripts/restore-db.sh storage/private/backups/workconnect-YYYYMMDD-HHMMSS.dump
```

The PostgreSQL restore verifies the checksum and creates a safety dump of the current target before replacing it.

Test or perform a restore (the script creates a safety copy first):

```bash
scripts/restore-db.sh storage/private/backups/workconnect-YYYYMMDD-HHMMSS.sqlite
```

Recommended daily cron entry:

```cron
15 2 * * * cd /Applications/XAMPP/xamppfiles/htdocs/WorkConnect && scripts/backup-db.sh >> storage/private/backups/backup.log 2>&1
```

Before production, set `APP_ENV=production`, an HTTPS `APP_URL`, PostgreSQL `DATABASE_URL`, a fresh encryption key, live Stripe keys, webhook secret, `MAIL_TRANSPORT=resend`, Resend credentials, and S3-compatible storage, then run:

```bash
php scripts/production-check.php
php scripts/reconcile-finance.php
```

Rotate `STRIPE_SECRET_KEY` in Stripe Dashboard before deployment. Never reuse a key that has previously been stored in source or shared.
