# Render Free deployment

Use [the SQLite deployment quickstart](docs/RENDER_SQLITE_QUICKSTART.md). The root `render.yaml` deploys a free demonstration with temporary SQLite storage and automatic sample-data setup. Render can erase all changes on restart or redeploy. Optional PostgreSQL configuration is in `render.postgres.yaml`.

# BranchFlow

A Laravel 13 + Livewire 4 application for a retail business with multiple branches. This is a working development version of the project **Design and Implementation of a Scalable Cloud-Based Platform for Data Aggregation and Real-Time Analytics**.

## Documentation for installation and project defence

- [Complete project guide (PDF)](docs/BRANCHFLOW_COMPLETE_GUIDE.pdf)
- [Complete guide, editable Markdown](docs/BRANCHFLOW_COMPLETE_GUIDE.md)
- [Defence revision sheet (PDF)](docs/DEFENCE_CHEAT_SHEET.pdf)
- [Defence revision sheet, editable Markdown](docs/DEFENCE_CHEAT_SHEET.md)

The complete guide explains the actual implementation, Windows/Linux/macOS setup, user workflows, architecture, database, testing, troubleshooting, maintenance and defence questions. It distinguishes implemented features from planned cloud deployment and load testing.

## What works

- Session login, login throttling, logout and CSRF protection.
- My account password changes; owner-managed staff profiles, branch reassignment, activation/deactivation and password resets.
- Session and remember-me invalidation after account/security changes, including Livewire access checks.
- Owner and branch-assigned staff accounts. Staff cannot access other branches' transactions, reports, imports, inventory or dashboard aggregates.
- Shared product catalog and branch creation, with stock balances initialized automatically.
- Stock receipts with a movement ledger; sales deduct inventory inside database transactions.
- Owner-controlled product editing, reasoned stock adjustments and transactional branch transfers with replay protection.
- Inventory search, branch/stock filters and pagination; staff-scoped transfer history.
- Catalog prices stored as integer kobo and copied onto each sale.
- UUID transaction references prevent replayed sales from deducting stock twice.
- Owner-authorized full-sale returns restore stock once and remove returned sales from revenue.
- Dashboard with branch/date filters, revenue trends, product rankings, low-stock alerts and automatic 10-second refreshes through Livewire polling.
- Durable database queue for CSV imports, per-row validation, progress/status, rejection details and safe retries using transaction references.
- Searchable/sortable sales history, transaction details, filtered sales reports and matching streamed CSV exports.
- Branch and product performance summaries, returned-sale values and an audit trail.
- Branch-scoped ingestion API with hashed keys, rotation/revocation, queued processing and an owner monitoring screen.

## Render deployment

Use SQLite locally and PostgreSQL for the hosted app. [Render deployment guide](docs/RENDER_DEPLOYMENT.md) explains the free web-container setup, external free PostgreSQL option, environment values, bootstrap account and durable CSV handling. Render resources have not been created by this preparation.

## Run locally

Requirements: PHP 8.3+, Composer, and the PHP extensions required by Laravel, including a PDO database driver. SQLite is the default local database. No Node build is required for the current interface: styles are served from `public/css/app.css`, and Livewire serves its own JavaScript.

```bash
composer install
cp .env.example .env
php artisan key:generate
# Create database/database.sqlite if it does not exist.
php artisan migrate
php artisan branchflow:owner
php artisan serve --host=127.0.0.1 --port=8000
```

In a second terminal:

```bash
php artisan queue:work ingestion --tries=3 --timeout=120
```

Open http://127.0.0.1:8000. The owner command prompts for the password without putting it in shell history. There are no built-in default credentials.

Alternatively the initial owner can be seeded using `OWNER_NAME`, `OWNER_EMAIL`, and `OWNER_PASSWORD` (12+ characters) in a local `.env`, followed by `php artisan db:seed`. Remove bootstrap credentials after creating the account. Never commit `.env`.

### Optional demo data

Create the owner first, then run:

```bash
php artisan db:seed --class=DemoSeeder
```

The development dataset includes three branches, 15 additional products with `DEMO-` SKUs, 720 sales across the last 30 days, 18 full-sale returns, three branch-assigned staff, six low/out-of-stock balances, three CSV imports (one accepted and one rejected row each), and three API sources with completed/rejected processing examples. Older demo records and any existing business records are preserved.

The seeder is disabled in production. It runs under a lock and database transaction. Deterministic references make same-day reruns idempotent: they do not duplicate sales, top up existing opening balances, reset passwords, or rotate keys. Running on a later day fills missing dates in the rolling 30-day window. It does not delete older records. Repeated use over many months may require receiving more stock for demo products.

Generated demo staff passwords and source keys are written once to the ignored `.local-demo-access.md` file, with owner-only file permissions. Keep this file private. The existing owner login remains in `.local-access.md`. Sources and files are explicitly labelled `DEMO`; processing examples use the real processors directly and are not presented as external network traffic. The seeder does not leave queued jobs behind.

### Typical workflow

1. Owner creates branches and products.
2. Owner receives opening stock with a delivery reference.
3. Owner creates staff accounts assigned to a branch.
4. Staff record sales; stock and dashboard figures update from stored transactions.
5. Owner reviews stock alerts, branch comparisons, reports and audit history.
6. Import sales from CSV where external records are available.
7. Open **Integrations** to create branch API sources and monitor incoming events. See [the API guide](docs/INGESTION_API.md).
8. Use **My account** for password changes and **Team members → Manage** for staff access. See [the account access guide](docs/ACCOUNT_ACCESS.md).
9. Use **Product catalog → Edit product**, **Inventory → Adjust stock**, and **Stock transfers** for inventory operations. See [the inventory guide](docs/INVENTORY_OPERATIONS.md).
10. Use **Reports** and the filters in **Sales transactions** to inspect and export selected results. See [the reporting guide](docs/SALES_REPORTING.md).

### CSV contract

The exact header is `reference,sku,quantity,sold_at`. See `public/sample-sales.csv`.

- UTF-8 CSV, max 2 MB and 5,000 data rows per file.
- Reference must be a globally unique UUID. Reuse the same reference when retrying the same transaction; a changed branch, product or quantity is rejected.
- SKU must match the catalog. Quantity must be a positive integer and stock must be available.
- `sold_at` must be a valid date/time no later than now. Include an offset for unambiguous external timestamps; naive timestamps use Africa/Lagos.
- Imports use the current catalog price. Historical externally priced transactions are not supported yet.
- Accepted rows commit independently. Rejected rows do not change stock. Accepted count includes matching replays; it is not a count of newly inserted sales.
- Imports reflect on the page after refresh. Worker failures appear in the failed-jobs table; use `php artisan queue:failed` and `php artisan queue:retry <id>` after addressing the failure.

## Verify

```bash
php artisan test
php artisan view:cache
```

Feature tests exercise login, workspace pages, branch permissions, overselling prevention, idempotent sales, returns, stock receipts, dashboard filtering, CSV validation/replays and currency exports, API authentication/replays, key lifecycle, rate limiting, atomic queue acceptance, worker outcomes and source isolation. Tests use a separate in-memory SQLite database.

## Deployment direction and remaining work

This milestone runs locally and has not been cloud-deployed or load-tested. It supports one business with multiple branches, not multiple independent tenant businesses.

For a live deployment, use a shared MySQL or PostgreSQL database, HTTPS, a persistent queue worker managed by Supervisor/systemd, `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, a stable `APP_KEY`, monitoring and tested database/file backups. Point the web root to `public/`. Run migrations through a controlled deployment step. SQLite is for development here; row-level locking and concurrent writers must be verified against the chosen production database. Set the database connection timezone consistently with the application (Africa/Lagos) or explicitly convert timestamps at ingestion.

Next milestones:

- WebSocket delivery and pre-aggregated metrics when polling/query volume warrants them.
- Formal transfer request/dispatch/receipt workflows, receipt replay protection and multi-product transfers.
- Email-based password recovery, owner account editing and MFA.
- Multi-line receipts, partial returns, supplier workflows, and configurable business identity.
- Production database integration tests, concurrency/load tests and measured scaling results.
- Cloud deployment, operational monitoring, backup/restore verification and a business pilot.

Do not present this milestone as a completed production system or claim scalability from framework choice alone. Stock is the platform's local ledger; no POS, payment, banking or accounting service is connected yet.

Queue implementation reference: [Laravel queues](https://laravel.com/framework/docs/13.x/queues). Interface reference: [Livewire installation](https://livewire.laravel.com/docs/4.x/installation).
