> Deployment update (17 November 2026): the default Render configuration uses temporary SQLite for a school demo. See [SQLite quickstart](RENDER_SQLITE_QUICKSTART.md). Latest SQLite suite: 122 tests, 743 assertions. Historical verification statements below describe their original checks.

# BranchFlow: Complete Project and User Guide

**Project title:** Design and Implementation of a Scalable Cloud-Based Platform for Data Aggregation and Real-Time Analytics  
**Application name:** BranchFlow  
**Guide prepared:** 13 November 2026  
**Audience:** Student, examiner, developer, business owner and person installing the software  
**Scope:** The implemented Laravel application in `branchflow/platform`

> BranchFlow is a working local development application. It has not yet been deployed to a cloud server or subjected to production load testing. Its dashboard refreshes every 10 seconds through polling. These distinctions should be stated accurately during the defence.

## How to use this guide

- For installation, read sections 4–7.
- For everyday use, read sections 8–11.
- For technical understanding, read sections 2–3 and 12–16.
- For maintenance and troubleshooting, read sections 17–19.
- For the defence, practise sections 20–23 and the separate defence revision sheet.

The installation commands assume you have received the project source files. They do not download this specific project from a public repository. No repository URL has been published for it.

## 1. Project overview

### 1.1 What the application does

BranchFlow gives a business with several branches one place to record sales, manage stock and compare branch performance. It accepts sales entered through its interface, sales uploaded in CSV files, and sales submitted through an authenticated API. It stores the records in a central database and calculates reports from them.

For example, a retail business may have branches in Wuse, Garki and Kubwa. Staff at each branch can record sales for their location. The owner can review all three branches, identify products that are running low, and record stock transfers between them.

### 1.2 The problem being addressed

When branches keep separate notebooks or spreadsheets, an owner may struggle to know total sales, which location is performing well, whether stock figures are correct, and who made a change. Bringing transactions into one system reduces the need to combine these records manually.

The software supports that workflow. It does not guarantee that a person entered truthful information or replace physical stock verification.

### 1.3 Objectives

1. Collect sales from multiple branch sources.
2. Validate incoming data before it changes inventory.
3. Store transactions and stock records centrally.
4. Combine records into revenue, quantity and branch/product summaries.
5. Refresh the dashboard automatically.
6. Restrict users to their permitted operations and branches.
7. Preserve an audit trail and prevent certain repeated requests from changing stock twice.
8. Provide a foundation that can be deployed and evaluated for larger workloads later.

### 1.4 Important terms in the title

| Term | Basic explanation | Meaning in this project |
| --- | --- | --- |
| Data aggregation | Collecting records and combining them into useful totals | Combining branch sales to calculate total revenue, units, rankings and trends |
| Analytics | Examining data to understand what happened | Revenue trends, branch comparisons, product rankings and stock alerts |
| Real-time | Showing changes soon after they happen | Near-real-time updates: the dashboard polls the server every 10 seconds, after records have been processed |
| Cloud-based | Running an application on an internet-accessible server | A deployment direction supported by the web architecture; current verification is local |
| Scalable | Able to handle growth by adding or improving resources | Queue-based ingestion, a separate worker and database-backed services provide a starting architecture; capacity has not been proven by load tests |

**A suitable project description:** “I implemented a multi-branch retail platform that brings sales into a central database, processes external transactions through a queue, and presents branch and inventory analytics through an automatically refreshing dashboard.”

## 2. What was used during development

The following versions were checked against the development environment and locked project dependencies. They describe the inspected build, not a promise that these are the latest available releases.

| Technology/tool | Inspected version or form | Why it is present |
| --- | --- | --- |
| PHP | 8.4.23 in the development environment | Runs the backend application |
| Laravel | 13.42.0 | Provides routing, database access, validation, authentication, queues and testing support |
| Livewire | 4.4.8 | Updates parts of the interface using PHP components and browser requests |
| Blade | Laravel's template engine | Builds the HTML pages |
| HTML and custom CSS | Project source files | Provide the interface structure and the navy, white and teal design |
| Alpine/JavaScript | Used through the Livewire browser environment and small interface behaviours | Handles actions such as the mobile navigation menu |
| SQLite | Local database file | Stores the local application data without requiring a separate database server |
| Composer | 2.7.1 in the development environment | Installs the PHP dependencies described by `composer.json` and `composer.lock` |
| PHPUnit | 12.5.43 | Runs the automated tests |
| Laravel Pint | Development formatter | Formats PHP source consistently |
| Chromium and Playwright | Browser verification tools | Were used to check actual browser workflows and responsive layouts |
| Node.js | 24.20.0 in the development environment | Supported browser verification/tooling; not needed to run the current interface |

The project contains Laravel scaffold files for Vite and Tailwind in `package.json`. The delivered interface uses `public/css/app.css` directly and Livewire's JavaScript assets. **It does not currently require an npm build to run.** Do not describe Tailwind, React or a Node server as the implementation of this interface.

The final application does **not** use the earlier proposed FastAPI backend. Redis and Docker are not required by the current local setup. MySQL and PostgreSQL have Laravel connection configurations, but the implementation and test results described here were verified with SQLite.

## 3. How the software was built

### 3.1 Development approach

The software was built in working increments:

1. Create the Laravel application and database structure.
2. Add owner/staff login and branch permissions.
3. Add branches, products, stock receipts, sales and returns.
4. Build the dashboard using stored transaction data.
5. Add queued CSV imports and downloadable reports.
6. Add API sources, branch keys and ingestion monitoring.
7. Expand the demo dataset and make reruns avoid duplicate demo transactions.
8. Add account security, staff management and session revocation.
9. Add product editing, stock adjustments and branch transfers.
10. Add shared sales filtering, reporting and transaction detail pages.
11. Check behaviour with automated tests and browser workflows.

This is an incremental development approach. It should not be presented as evidence of stakeholder interviews, formal Scrum ceremonies, a production pilot or a research methodology that was not actually carried out. The student should align the written methodology with the evidence available.

### 3.2 The design pattern

The application follows Laravel's **Model–View–Controller (MVC)** structure, with additional service classes, middleware and background jobs.

- **Model:** Represents database records. Example: `Sale` represents a sale.
- **View:** Displays information. Example: a Blade template displays the sales table.
- **Controller:** Receives a request, validates/coordinates it and chooses a response.
- **Service:** Contains business rules shared by several entry points. Example: `SaleService` records a sale whether it came from a web form, CSV or API.
- **Middleware:** Checks a request before it reaches the feature. Example: account access and API-key checks.
- **Job:** Describes work that can be processed in the background. Example: processing an accepted API sale.

A simple analogy is a shop: the controller is the attendant receiving a request, the service applies the shop's rules, the model/database keeps the records, and the view presents the result to the user.

### 3.3 Why Laravel was suitable

Laravel brings the main web-application components into one framework. Using its existing routing, validation, ORM, sessions, hashing, queue and test facilities reduces the amount of infrastructure that must be written from scratch. The choice does not automatically make the application secure or scalable; the rules and tests still matter.

## 4. Before installing on another computer

### 4.1 Required software

- PHP **8.3 or later** compatible with `composer.lock`; PHP 8.4.x is a suitable starting point for reproducing this build.
- Composer 2.
- A current web browser.
- The project source folder.
- Internet access for the first Composer dependency installation.
- A PHP SQLite PDO driver for the default database.

A laptop with 4 GB RAM and available disk space for PHP, dependencies and the database is a practical starting point for a school demonstration. More memory is helpful. These are practical suggestions, not measured minimum hardware requirements.

Laravel also requires its standard PHP extensions. Use the [official Laravel server requirements](https://laravel.com/framework/docs/13.x/deployment#server-requirements) and run Composer's platform check. Relevant extensions include PDO, Mbstring, OpenSSL, Fileinfo, XML/DOM, Ctype, cURL, Session, Filter, Hash, PCRE and Tokenizer; SQLite additionally needs its PDO driver. ZIP support or an unzip utility helps Composer extract packages.

### 4.2 Choose the correct project folder

The application root is the folder containing `artisan`, `composer.json`, `app`, `routes` and `resources`.

If you received the parent folder `branchflow`, enter its `platform` subfolder. Do not run the application commands from another project or the parent directory.

Example Windows location:

```text
C:\Projects\branchflow\platform
```

Example Linux/macOS location:

```text
~/Projects/branchflow/platform
```

### 4.3 Fresh installation versus moving an existing database

A **fresh installation** starts with an empty database and a new application key. Follow sections 5–7.

Moving an **existing installation with its records** is different: back up the existing database, private files and environment configuration, preserve its application key, and follow the restore notes in section 18. Do not overwrite an existing `.env` or generate a new key as a routine update.

Do not copy environment secrets from an unrelated project. Each independent installation should have its own configuration.

## 5. Install PHP and Composer

### 5.1 Windows

1. Install a compatible PHP release using the [official PHP Windows instructions](https://www.php.net/manual/en/install.windows.php). Check that the version is at least 8.3; PHP 8.2 will not satisfy this project.
2. Make sure the folder containing `php.exe` is available through your user `PATH`.
3. Configure PHP extensions in the `php.ini` actually used by the command line. `php --ini` shows this file.
4. Install Composer using the [official Composer Windows installer guidance](https://getcomposer.org/doc/00-intro.md#installation-windows). Select the same compatible PHP executable when prompted.
5. Close and reopen PowerShell after installation or PATH changes.
6. Check:

```powershell
php -v
composer -V
php --ini
php -m
```

If a PHP bundle is already installed, check its version rather than assuming it is compatible. The command-line PHP and a web-server PHP installation may use different configuration files.

### 5.2 Linux: Debian/Ubuntu example

On a distribution whose repositories provide PHP 8.3 or later, the following installs common prerequisites:

```bash
sudo apt update
sudo apt install php-cli php-mbstring php-xml php-curl php-zip php-sqlite3 composer unzip
php -v
composer -V
```

Package versions differ by distribution. If `php -v` reports an older version, install a supported compatible PHP version using the [PHP installation documentation](https://www.php.net/manual/en/install.php) or your distribution's instructions before continuing. Do not ignore Composer's PHP-version errors.

### 5.3 macOS

If Homebrew is already available, a compatible PHP branch can be installed with:

```bash
brew install php@8.4 composer
export PATH="$(brew --prefix php@8.4)/bin:$PATH"
php -v
composer -V
```

The PATH command selects that PHP version for the current terminal. See the [official Homebrew PHP 8.4 formula](https://formulae.brew.sh/formula/php@8.4) for current installation details. The remaining application setup steps are the same as Linux.

## 6. First-time application setup

Run commands in the order shown. Use your own folder location.

### Step 1: Open the application directory

Windows PowerShell:

```powershell
cd "C:\Projects\branchflow\platform"
```

Linux/macOS:

```bash
cd "$HOME/Projects/branchflow/platform"
```

### Step 2: Create the local environment file

Only do this when creating a fresh `.env`.

Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

Linux/macOS:

```bash
cp .env.example .env
```

`.env` contains local settings such as the application URL and database connection. `.env.example` is the shareable template. Real `.env` files and credential files should remain private.

### Step 3: Install locked PHP dependencies

```bash
composer install
composer check-platform-reqs
```

Use `install` to reproduce the versions in `composer.lock`. Do not use `composer update` as a normal installation step: that changes dependency versions.

If the platform check fails, install/enable the missing PHP version or extension. Do not bypass it with `--ignore-platform-reqs`.

### Step 4: Generate the application key

```bash
php artisan key:generate
```

This creates the key used by Laravel's encryption-related features, including encrypted cookies. It is not a password and is not an external branch API key. Keep it private and stable for an existing installation.

### Step 5: Use the default local settings

Confirm these entries in `.env`:

```dotenv
APP_NAME=BranchFlow
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=sqlite
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
```

Leave `DB_DATABASE` unset/commented for Laravel's default `database/database.sqlite` path. If you use a custom path, provide the correct absolute path for that computer.

Redis, AWS and mail settings in `.env.example` are scaffold options. They are not needed for the default local demonstration. The configured mail driver logs mail; there is no implemented email password-recovery flow.

### Step 6: Create the SQLite database file

This command works through PHP on Windows, Linux and macOS:

```bash
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
```

SQLite stores the local database in this file. There is no separate MySQL service to start for this setup.

### Step 7: Create the tables

```bash
php artisan migrate
```

A migration is a versioned description of a database change. This command creates or updates the application's tables without intentionally clearing existing records.

**Do not use `migrate:fresh` on a database you want to keep. It drops the tables.**

### Step 8: Create the business owner

```bash
php artisan branchflow:owner
```

Enter the owner's name, email address and password when prompted. Password input is hidden. Use at least 12 characters and stay within the application's 72-byte password limit; a normal ASCII passphrase of 12–72 characters is straightforward.

There are no universal built-in login credentials. A new installation uses the owner account you create here. Developer-local credential files are not automatically supplied to another computer.

### Step 9: Optionally load demonstration data

```bash
php artisan db:seed --class=DemoSeeder
```

On an otherwise fresh database after creating the owner, this produces:

- Three branches: Wuse, Garki and Kubwa.
- Fifteen products with `DEMO-` SKUs.
- 720 demo sales across the recent 30-day window, including 18 full returns.
- Three branch staff accounts.
- Six low/out-of-stock balances: one low and one zero balance per branch.
- Three API sources, with three completed and three rejected processing examples.
- Three CSV imports, each with one accepted row and one rejected row.
- Three API sales and three CSV sales in addition to the 720 demo-source sales.

Consequently, a fresh seeded installation has **726 sales records**, not necessarily the same count as the developer's accumulated database. Existing older demo data and testing records can increase counts. Do not memorise a development database count as a universal requirement.

The seeder preserves existing records. Same-day reruns skip matching demo transactions and do not reset existing passwords or API keys. A later-day rerun adds missing dates in the rolling window without deleting older data. Long-term repeated use can require replenishing demo stock.

Newly generated staff passwords and demo API keys are placed in `.local-demo-access.md` on that computer. Keep it private. It is not a public user manual and is intentionally excluded from source control. Demo seeding is blocked in production by default. The explicit school-demo deployment option is described in the Render deployment guide.

The API examples are processed directly as seeded fixtures; they are not evidence of external network requests. Demonstrate the real endpoint separately when explaining integration.

### Step 10: Start the web server

Terminal 1:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Open `http://127.0.0.1:8000` in your browser and sign in with the owner account you created.

### Step 11: Start the background worker

Open a second terminal, enter the same application directory and run:

```bash
php artisan queue:work ingestion --tries=3 --timeout=120
```

Keep both terminals open. The web server serves pages. The worker processes background jobs.

The supplied `ingestion` connection uses the application's database and default jobs queue. With the supplied settings, this worker can process both API jobs and CSV imports. If someone changes the queue database, connection or queue names, separate workers may be needed.

Windows can run the development workflow, but Unix process signals/timeouts have different support. Use Linux/WSL or a suitably managed server environment for production worker supervision.

### Step 12: Check the installation

```bash
php artisan test
```

At the checked build, the suite contains **103 passing tests**. Tests use an in-memory SQLite database configured by `phpunit.xml`, rather than the regular development database. That database separation depends on preserving the supplied test configuration.

You can also check that Blade templates compile:

```bash
php artisan view:cache
```

## 7. Starting the application after the first installation

You do not need to reinstall dependencies, regenerate the key, recreate the owner or reseed every time.

1. Open terminal 1 in `platform` and run the web-server command.
2. Open terminal 2 in `platform` and run the worker command.
3. Open the application URL and log in.
4. Press `Ctrl+C` in each terminal to stop its process when finished.

If port 8000 is occupied by another project, choose a free port:

```bash
php artisan serve --host=127.0.0.1 --port=8017
```

Set `APP_URL=http://127.0.0.1:8017` for that installation and run `php artisan config:clear` after changing configuration. Use the same host/port consistently in your browser and API client. Do not stop another project's server just to reuse its port.

The `127.0.0.1` address is only this computer. A classmate cannot open your computer's localhost address from their laptop. Shared/LAN/cloud access requires a deliberately configured reachable server.

## 8. User roles and menus

| Feature | Owner | Staff |
| --- | --- | --- |
| Dashboard | All branches or a selected branch | Assigned branch only |
| Record sales | Any branch | Assigned branch only |
| Inventory balances | All branches | Assigned branch only |
| Receive stock | Yes | No |
| Adjust stock | Yes | No |
| Create a stock transfer | Yes | No |
| Read transfer history | All transfers | Transfers involving their branch |
| Branch and product management | Yes | No |
| Staff account management | Yes | No |
| Authorise full-sale returns | Yes | No |
| Reports and exports | All permitted branch data | Assigned branch only |
| CSV imports | Any branch | Assigned branch only |
| Integration/key management | Yes | No |
| Change own password | Yes | Yes |
| Audit activity | Workspace-wide | Assigned branch records |

These are server-side restrictions. Hiding a button is only a convenience; submitting a forbidden URL or branch ID is also checked on the server.

## 9. Operating the business workflow

### 9.1 Create branches and products

The owner opens **Branches**, enters a branch name/location and saves. In **Product catalog**, the owner creates a product with a name, unique SKU, category, price in NGN and reorder level.

An SKU is a stable product code such as `RICE-5KG`. Imports and API senders use this code to identify the product. New branches/products are given corresponding zero-stock balances. Creating a product does not imply that stock has arrived.

Use **Edit product** to change descriptive details, future price or reorder level. The SKU stays fixed. An old sale keeps its recorded price even if the catalog price changes later.

### 9.2 Receive stock

Open **Inventory**. Select the branch and product, enter the quantity received, and enter the delivery reference/reason. Saving increases the stock balance and records a receipt movement and audit entry.

Unlike sale/transfer/adjustment operations, this original receipt form does not yet have an operation-level idempotency key. Check that a delivery was not already recorded before entering it again.

### 9.3 Create staff

The owner uses **Team members** to enter a name, email, assigned branch and initial password. Staff can then log in and should change their password in **My account**.

Owners can use **Manage** to edit staff details/branch assignment or reset a staff password. A reset requires the owner's own current password. Share new passwords privately. Password reset does not automatically reactivate an inactive account.

Deactivate an account when access is no longer required. Its history remains. Reactivating it permits a fresh login; it does not restore old sessions.

### 9.4 Record a sale

Open **Sales transactions**, select the branch/product and quantity, then record the sale. The server checks stock, obtains the catalog price, stores the sale, reduces stock, and records ledger/audit entries in a transaction.

One sale record currently represents **one product line**. It is not a multi-product receipt or a payment checkout. Payments, customer balances, tax calculation and invoice settlement are not implemented.

Example: two units at ₦1,250.99 produce a saved total of ₦2,501.98 and reduce stock by two units.

### 9.5 Return a sale

An owner can use the return action in the sales list or the sale detail page. A full return restores all units, marks the sale as returned and excludes it from completed-sales revenue. Repeating the return does not restore the stock a second time.

Partial returns and actual money refunds are not implemented. A returned record is not proof of a bank/card refund.

### 9.6 Adjust stock

Use **Inventory → Adjust stock** on the branch/product row. Enter the **total usable units remaining**, not merely the units to remove. Select physical count, damage, expiry or correction and explain the adjustment.

For example, if the recorded balance is 20 and two units are damaged, enter 18 with a damage reason. The ledger records a change of −2.

Damage/expiry cannot increase stock. Negative and unchanged counts are rejected. If stock changes while the form is open, refresh and verify the latest count before submitting again. This avoids overwriting a sale or receipt that occurred in the meantime.

### 9.7 Transfer stock

Use **Stock transfers** to record a completed physical movement. Select different sending and receiving branches, product, quantity and dispatch reference/reason.

If Wuse sends five units to Garki, Wuse decreases by five and Garki increases by five. The total across both branches is unchanged. Both ledger entries belong to one transfer operation.

This version updates both balances immediately. It does not model approval requests, stock in transit, partial receipt or receiving-branch acceptance. Record a transfer only when the actual movement is complete. A correction can be recorded as a new opposite transfer with an explanation.

### 9.8 Review the dashboard and reports

The dashboard shows completed revenue, transactions, units sold, stock alerts, daily revenue, branch rankings, recent transactions and top products. Use the branch and 7/30/90-day filters. Stock alerts represent current balances, even when the sales period changes.

Open **Reports** or use the sales-list filters to select branch, product, dates, status and source. Search by reference, product name or SKU. Sort by time or amount. Exports include all matching pages and preserve the chosen filters and transaction sort order.

The period uses the **original sale date**, including both selected calendar days in Africa/Lagos. Returned sale value is displayed separately and excluded from completed revenue. This is not a refund-by-date or profit report.

Click a sale reference to inspect its full reference, saved amounts and related stock movements.

## 10. Importing CSV data

CSV means comma-separated values: a text format commonly opened by spreadsheet applications.

The exact header is:

```csv
reference,sku,quantity,sold_at
```

Example row format:

```csv
550e8400-e29b-41d4-a716-446655440000,RICE-5KG,2,2026-11-12T10:00:00+01:00
```

Use a SKU that actually exists in your installation, available branch stock, a valid past/current date and a new UUID for a genuinely new sale. The example reference is a placeholder; do not reuse it for different transactions.

1. Open **Data imports**.
2. Select the correct branch.
3. Upload the file.
4. Keep the worker running.
5. Refresh the import history and inspect accepted/rejected counts and reasons.

The limit is 2 MB and 5,000 data rows per import. Each valid row is processed independently. A rejected row does not deduct stock; earlier accepted rows remain recorded. Accepted counts can include matching transaction replays, so they are not always counts of newly created sales.

Stock and current catalog prices are checked during processing. Historical externally supplied prices are not supported. A later permission change, such as deactivating or moving the submitting staff account, can prevent an import from processing with obsolete access.

## 11. Connecting another system through the API

### 11.1 Basic explanation

An API is a defined way for one program to send information to another. Here, a branch program can submit a sale as JSON instead of a person typing it into a form. JSON is a structured text format using named fields.

An API key identifies and authorises the sending source. It is not the owner's password. Each source is assigned to one branch.

### 11.2 Create a source

The owner opens **Integrations**, names the source and assigns its branch. The full key is shown once. Store it privately in the sending system. The source table stores its SHA-256 hash, not a recoverable plaintext key. The key is temporarily flashed in the owner's session for its one-time display.

Rotation creates a new key and invalidates the old one. Revocation blocks future submissions and status reads. Already accepted events still process when their originating account remains authorised.

### 11.3 Submit and check

Send `POST /api/v1/sales` with these headers:

```http
Authorization: Bearer YOUR_API_KEY
Content-Type: application/json
Accept: application/json
```

Example body:

```json
{
  "reference": "550e8400-e29b-41d4-a716-446655440000",
  "sku": "RICE-5KG",
  "quantity": 2,
  "sold_at": "2026-11-12T10:00:00+01:00"
}
```

A 202 response means **accepted for processing**, not completed. Keep the worker running and check `GET /api/v1/sales/{reference}` with the same source key. The returned `status_url` also identifies that endpoint.

A graphical HTTP client can be used for the defence: select POST, enter the URL, set the three headers, choose a raw JSON body and send. For the status check, change to GET, use the reference URL and keep the Authorization/Accept headers.

| Event status | Meaning |
| --- | --- |
| queued | Waiting to be processed or automatically retried |
| completed | Sale and its stock movement were committed |
| rejected | A business rule failed, such as insufficient stock |
| failed | Processing exceptions exhausted automatic retries |

The endpoint accepts an existing SKU, positive integer quantity and a timestamp with whole seconds and an explicit offset (`Z` or `+01:00`, for example). Future dates are rejected. The source determines the branch; the catalog determines prices. Clients cannot override these through the payload.

The body limit is 16 KB. The source limit is 120 requests/minute, shared by submissions and status checks; a separate 600/minute per-IP edge limit also applies. Do not poll continuously without a delay.

Common HTTP results: 401 invalid key; 404 unavailable/reference owned by another source; 409 reference conflict; 413 too large; 415 wrong content type; 422 validation error; 429 rate limit.

Use the **same reference and same payload** when retrying a request after a timeout. A matching replay returns the existing event. Changing the data or using another source with the same API reference is rejected.

No vendor-specific POS connection is included automatically. Another system must be configured/programmed to send this contract.

## 12. Understanding the architecture

### 12.1 Web sale flow

```text
User submits sale form
        ↓
Session/account and branch permission checks
        ↓
Controller validates input
        ↓
SaleService checks and locks the stock balance
        ↓
Save sale + reduce stock + ledger + audit
        ↓
Commit transaction and return a response
        ↓
Dashboard sees committed data on a later poll
```

Web sales are processed in the request itself. They are not queued.

### 12.2 API sale flow

```text
External branch system sends JSON with API key
        ↓
Authenticate source, check rate/body limits, validate
        ↓
Commit ingestion event + database queue job together
        ↓
Queue worker runs ProcessIngestion
        ↓
SaleService applies the normal sales/stock rules
        ↓
Event becomes completed, rejected or failed
        ↓
Monitoring/dashboard displays the saved result
```

The API event and its queue entry use the same application database transaction. If that transaction fails, neither should be accepted alone. New CSV uploads also commit their import record, pending content and queue job together in the application database. The worker can recover them if the temporary upload file is lost.

### 12.3 Why have a queue?

A queue is a waiting line for work. It lets the API acknowledge accepted work without performing all processing in that HTTP request. The worker takes jobs from the line and processes them.

If the worker is stopped, accepted jobs remain pending in the database and the dashboard cannot display unprocessed sales as completed. When the worker restarts, it can process pending jobs. A queue buffers work; it does not guarantee unlimited capacity or automatically process jobs faster.

The current queue is database-backed. It is not Kafka, RabbitMQ or Redis.

### 12.4 What updates the screen?

Livewire components use `wire:poll.10s` to request updated data approximately every 10 seconds while the page is active. Background tabs and browser scheduling can affect timing. This is **near-real-time polling**, not a WebSocket push system or an instantaneous guarantee.

## 13. Main database records

A database table stores one type of record. An ID identifies a record; a foreign key connects it to another table.

| Table | What it stores |
| --- | --- |
| users | Owners/staff, hashed passwords, branch assignment, active status and access version |
| branches | Branch names and locations |
| products | Shared catalog, SKU, price in kobo and reorder level |
| stocks | One current balance for each branch/product pair |
| sales | Reference, branch, product, actor, quantity, recorded price/total, source, time and status |
| stock_movements | Receipts, sales, returns, adjustments and transfer ledger entries |
| stock_operations | Referenced adjustments and transfers, their reasons and retry fingerprints |
| audit_logs | Actor, action, branch, description and recording time |
| imports | Uploaded CSV metadata, processing result, accepted/rejected counts and errors |
| api_sources | Named branch sources, key hashes, key prefixes and revocation/activity metadata |
| ingestion_events | API payloads, references, outcome, errors and related sale |
| jobs / failed_jobs | Pending work and exhausted queue failures |
| cache / cache_locks | Default cache and lock storage |
| sessions | Default browser session storage |
| password_reset_tokens / job_batches | Laravel scaffold tables; their presence does not mean email recovery or job batching is implemented |

Main relationships:

```text
Branch ──< Stock >── Product
Branch ──< Sale  >── Product
User   ──< Sale
Sale   ──< StockMovement
StockOperation ──< StockMovement
Branch ──< ApiSource ──< IngestionEvent >── Sale (when completed)
User/Branch ──< AuditLog
```

The `stocks` table gives a quick current balance. The movement ledger explains why it changed. The unique branch/product pair prevents creating multiple independent balance rows for the same combination.

The application supports **one business with several branches**. It is not currently a multi-tenant service for independent companies sharing an installation.

## 14. Calculations and data integrity

### 14.1 Money

Money is stored as integer kobo rather than a floating-point amount. ₦1,250.99 is stored as `125099`. Two units cost `250198` kobo, displayed as ₦2,501.98.

This keeps stored monetary arithmetic exact within the supported range. Product creation/editing converts validated decimal input directly to integer kobo.

### 14.2 Reporting formulas

- Sale total = recorded unit price × quantity.
- Completed revenue = sum of recorded totals for matching completed sales.
- Units sold = sum of quantities for matching completed sales.
- Returned sale value = sum of original totals for matching returned sales.
- Low stock = current balance at or below the product's reorder level.
- Transfer conservation = source reduction equals destination increase.
- Adjustment change = counted available balance minus the previous recorded balance.

This is descriptive analytics: it explains recorded activity. It does not predict demand using machine learning or calculate profit from supplier costs.

A simplified aggregation query is:

```sql
SELECT branch_id,
       COUNT(*) AS transactions,
       SUM(total_minor) AS revenue_minor
FROM sales
WHERE status = 'completed'
GROUP BY branch_id;
```

This means: take completed sales, place records from the same branch together, count them, and add their saved totals. The application also applies the user's branch permissions and selected filters. The resulting money is still in kobo until formatted for display.

### 14.3 Transactions

A database transaction groups changes so they commit together or roll back together. For a transfer, a failure must not leave one branch reduced without increasing the other. For a sale, stock, sale, ledger and audit changes are grouped.

### 14.4 Locks and stale counts

The services use database transactions and row-locking calls for relevant balances. Transfers lock both balances in a stable order. Adjustments additionally compare the balance shown when the form was opened with the current balance.

SQLite's concurrency behaviour differs from MySQL/PostgreSQL row locking. Local tests do not establish that every production concurrency scenario has been verified. Testing against the chosen production database remains necessary.

### 14.5 Idempotency

Idempotency means repeating the same request does not apply the business effect a second time. Sales, API acceptance, transfers and adjustments use references and matching-data checks to protect retries. A return is also protected against restoring stock twice.

Idempotency does not mean “the whole system never contains duplicates.” If a sender assigns a new reference to the same real-world sale, the system cannot automatically know it is a duplicate. Stock receipt entry also does not yet have operation-level replay protection.

## 15. Security explained simply

| Control | Purpose |
| --- | --- |
| Password hashing | Stores a one-way password representation instead of the readable password |
| Server-side permissions | Checks role and branch even if somebody changes a URL/form |
| Session regeneration | Changes the session identifier after login/password change |
| Active-account/access-version checks | Rejects obsolete sessions after account or security changes |
| Remember-token rotation | Prevents old remembered logins from restoring revoked access |
| CSRF protection | Helps prevent another site from submitting authenticated web forms without the expected token |
| Validation | Rejects invalid types, dates, quantities and unsupported values |
| Source-key hashing and scoping | Protects machine credentials and limits each source to its branch |
| Rate limits | Restricts repeated login/API attempts and request volume |
| Private import storage | Keeps uploaded files under the private application storage area |
| Audit history | Records actions and actors for investigation/accountability |

Authentication answers “Who are you?” Authorisation answers “What are you allowed to do?” Hashing a password is different from encrypting a cookie: passwords are checked against hashes, while encrypted data can be decrypted using the key.

Security is not absolute. MFA, email recovery, a formal penetration test, a production monitoring service and complete tamper-proof logging are not included. The application audit log is useful, but a database administrator with sufficient access can alter database records.

## 16. Testing and quality checks

At this build, **103 automated tests pass**. They cover authentication, permissions, queues, CSV validation, API keys, reference replays, stock checks, returns, account/session revocation, product prices, transfers, rollback, stale adjustments, report totals, date boundaries and exports.

Browser checks also exercised login, account flows across separate sessions, transfers/adjustments, actual HTTP ingestion and a worker, filtered exports, and responsive layouts at 390, 768 and 1024 pixel widths. Browser automation is development verification tooling; its temporary scripts are not a required part of the application runtime.

The documented setup sequence was also checked in a separate clean Linux copy: dependency installation, migrations, owner creation, demo seeding and the 103-test suite succeeded. The Windows/macOS instructions follow the supplied project commands and official prerequisite guidance; those operating systems were not independently exercised during this documentation task.

A passing test suite shows that the checked cases behaved as expected. It does not prove unlimited traffic capacity, absence of every vulnerability, correct operation on every operating system, or suitability for immediate production use.

**During the defence:** show a command result from the installed copy instead of merely claiming the tests pass.

## 17. File structure and where to explain the code

| Location | Explanation |
| --- | --- |
| routes/web.php | Browser URLs and their controllers |
| routes/api.php | External sales submission/status endpoints |
| bootstrap/app.php | Application routing and web middleware registration |
| app/Http/Controllers | Request handling for workspace, accounts, teams, integrations, products, inventory and reports |
| app/Http/Middleware | Active-session and source-key checks |
| app/Models | Database record definitions and relationships |
| app/Services/SaleService.php | Sale, stock deduction and full return rules |
| app/Services/InventoryService.php | Transfer/adjustment rules and replay checks |
| app/Services/BranchAccess.php | Shared owner/branch permission checks |
| app/Services/SalesQuery.php | Shared sales filters, sort and summary logic |
| app/Jobs | CSV/API background processing |
| app/Livewire | Dashboard and ingestion monitoring components |
| app/Support/Money.php | Decimal price to integer-kobo conversion |
| app/Rules/PasswordInput.php | Additional password input limits |
| database/migrations | Versioned table/column definitions |
| database/seeders | Owner bootstrap and reproducible demo data |
| resources/views | Blade pages and reusable interface pieces |
| public/css/app.css | Current interface stylesheet |
| storage/app/private | Private local files, including imports |
| storage/logs/laravel.log | Default application error log |
| tests/Feature | Application behaviour tests |
| .env.example | Safe configuration template |
| composer.lock | Reproducible PHP dependency versions |

To explain a sale in code, start with `routes/web.php`, follow the sale action in `WorkspaceController`, then open `SaleService`, `Sale`, `Stock` and the relevant migration. Finish with the sales Blade view and a test of insufficient stock or repeated references.

## 18. Maintenance, backup and moving the project

### 18.1 Updating an existing installation

Back up first, then install the supplied locked dependencies, apply pending migrations and restart long-running workers:

```bash
composer install
php artisan migrate
php artisan optimize:clear
php artisan queue:restart
```

Do not replace the existing `.env`, regenerate the key, run `migrate:fresh`, or reseed simply because the application was restarted.

### 18.2 Local SQLite backup

For a simple consistent local backup, stop the web server and worker so no process is writing. Copy:

1. The database file `database/database.sqlite`.
2. Private uploads under `storage/app/private`.
3. The existing `.env` and application key into a protected backup.
4. The source files, migrations and `composer.lock` matching the database version.

If another SQLite-writing process is still running, copying only the main file may be insufficient; use a database-aware backup procedure. A backup is only trustworthy once restoration has been tested.

Restore into a separate location first. Put the database and private files back, configure their paths, preserve the matching key, install dependencies, clear cached configuration, apply genuinely pending migrations and test login/data access. Old sessions may need fresh login after moving the installation.

### 18.3 What to share with another person

For a fresh install, share source, migrations, seeders, tests, guides, `composer.json`, `composer.lock`, `.env.example` and required public assets. The recipient can install `vendor` using Composer.

Do not put real `.env`, application keys, source API keys, private credential files or an actual business database into a publicly shared archive. If transferring an existing installation, transfer those sensitive parts privately with the database backup and document which environment it belongs to.

## 19. Troubleshooting

| Symptom | Likely explanation | What to do |
| --- | --- | --- |
| php/composer is not recognised | Not installed or absent from PATH | Install/configure it, reopen the terminal, check versions |
| Could not open input file: artisan | Wrong directory | Enter the folder containing artisan |
| vendor/autoload.php missing | Dependencies not installed | Run composer install |
| Composer says PHP is too old | CLI version below project requirement | Use compatible PHP; check which executable the terminal uses |
| Could not find driver | Database PDO driver missing | Enable/install pdo_sqlite for SQLite and check php --ini |
| SQLite file does not exist | Empty/custom path not created | Create the file and verify DB_DATABASE/path |
| No application encryption key | Key missing on a fresh install | Run key:generate only for that new installation |
| No such table | Migrations not run or wrong database selected | Check configuration, then run migrate |
| Address already in use | Another server occupies the port | Choose another free port and consistent APP_URL |
| Imports/API events stay queued | Worker not running, wrong queue or worker failing | Run the ingestion worker in the application directory; inspect logs |
| API 202 but sale not on dashboard | Accepted is not yet completed | Check event status, worker and active dashboard filters |
| Sale/transfer rejected for stock | Source branch has insufficient stock | Receive stock or correct the quantity; do not bypass the check |
| Adjustment says stock changed | Balance changed after opening the form | Refresh and verify the new count |
| Invalid login for demo staff | Wrong installation's password, changed password or inactive account | Check the local credential file; owner can manage/reset staff |
| Session ended | Account/version changed or old installation session | Sign in again; ask owner if access is inactive |
| 419/Page Expired | Session/CSRF problem or stale form | Refresh/login, use one host/port consistently, check session configuration |
| API 401 | Wrong/rotated/revoked key or unavailable source account | Check the correct source and create/rotate through Integrations |
| CSV rows rejected | Header, SKU, date, quantity or stock issue | Read row errors and correct the data |
| Fonts look different offline | Stylesheet requests Google Fonts | The interface falls back to system fonts; core data is still local |
| Permission denied writing logs/cache | Storage directories not writable | Give the application user appropriate access to storage/bootstrap cache; do not solve it by making everything world-writable |

Useful diagnostic commands:

```bash
php --ini
php -m
composer check-platform-reqs
php artisan migrate:status
php artisan optimize:clear
php artisan queue:failed
```

After investigating an exhausted queue failure, an administrator can retry its specific failed-job ID with `php artisan queue:retry JOB_ID`. An owner can also use the Integrations screen to retry an API event after addressing the cause. Repeating requests blindly can obscure the underlying problem.

## 20. Suggested defence demonstration: 10–15 minutes

1. **Introduce the problem:** several branches need one reliable sales/stock overview.
2. **Name the stack:** PHP, Laravel, Blade, Livewire, SQLite and a database-backed queue.
3. **Show the dashboard:** explain that data is aggregated from stored completed sales and refreshes by polling.
4. **Record one sale:** select stocked product/branch, save, inspect the full reference and stock reduction.
5. **Show a stock check:** attempt to sell more than is available and explain the rejection.
6. **Show branch permissions:** in a separate browser/session, use staff credentials and explain the limited branch view.
7. **Show aggregation:** filter reports by branch/product/date and download matching CSV results.
8. **Show asynchronous ingestion:** stop the worker, submit a genuine API request, observe queued status, restart the worker and show completion. This requires a real sender request; seeded examples do not replace it.
9. **Explain a duplicate:** resend the identical API reference/payload and show that no second sale is created.
10. **Show an inventory operation:** transfer stock or record a reasoned adjustment and inspect its history.
11. **Show evidence:** run the automated tests and point to the sale/transfer service code.
12. **State the limits:** local verification, 10-second polling, no load-test capacity claim and no completed cloud deployment.

Use a prepared demonstration dataset. Know the owner/staff credentials and ensure sufficient stock before the presentation. Do not display environment keys or full source keys on a projected screen. A saved fallback screenshot can help explain the interface, but it should not be presented as proof that a failed live operation succeeded.

## 21. Short presentation script

“BranchFlow is a multi-branch retail data aggregation and analytics application. The problem is that separate branch records make it difficult to see overall sales and stock. The application accepts sales through web forms, CSV uploads and an authenticated branch API.

“I built the backend with Laravel and PHP, the interface with Blade, Livewire and custom CSS, and used SQLite for the local database. A background worker processes external jobs, while a shared sales service applies stock and transaction rules.

“Owners can view all branches and manage inventory and staff. Staff are restricted to their assigned branch. The dashboard calculates completed revenue, quantities and branch/product performance and refreshes approximately every ten seconds.

“The application uses database transactions, permission checks, hashed credentials and reference-based replay protection. Automated and browser tests cover these workflows. The current build is locally verified; cloud hosting and measured load testing are the next stages.”

The student should adapt this to their own contribution and understanding. Do not claim sole authorship or activities that did not occur if asked how the implementation was produced.

## 22. Defence questions and simple answers

### 1. What exactly did you build?

A Laravel web application that combines sales and inventory information from several branches, provides reports, and controls who can access each branch's data.

### 2. Where does its data come from?

Web entry, CSV imports and an authenticated HTTP API. The demo seeder supplies labelled sample data for demonstration.

### 3. What is being aggregated?

Stored sales are grouped and summed by time, branch and product. For example, total completed revenue combines recorded sale totals from the selected branches.

### 4. Why use Laravel?

It supplies established web components such as routing, validation, ORM, sessions, queues and tests, allowing the application to focus on business rules.

### 5. Why Livewire rather than React?

Livewire fits the PHP/Laravel application and provides interactive server-driven components without a separate React application. The current interface does not use React.

### 6. Is it actually instantaneous real-time?

No. It is near-real-time: the dashboard polls about every ten seconds, and queued records appear after processing. WebSockets are a possible improvement.

### 7. Where is the cloud server?

The current build is locally verified. Cloud deployment is still outstanding. The application is web-based and structured for later hosting, but localhost is not a cloud deployment.

### 8. How is scalability demonstrated?

The architecture separates acceptance from background processing and uses database-backed services, but actual capacity has not been demonstrated through production load tests. A proper evaluation would measure throughput, response time, queue delay and errors as workload/workers increase.

### 9. Which database is used?

SQLite in development and tests. MySQL/PostgreSQL configurations are available for a future server deployment, which still needs database-specific verification.

### 10. Why a queue?

It allows accepted external work to wait durably for a worker rather than doing all work in the request. It also supports retries, but the worker must be running.

### 11. What if the worker stops?

Accepted jobs remain pending in the database. Processing resumes when the worker restarts. The system cannot show unprocessed sales as completed.

### 12. What does HTTP 202 mean?

The request was accepted for later processing. It does not mean that stock was deducted or the sale completed successfully.

### 13. How do you avoid repeated sales after a timeout?

The sender keeps the same UUID and payload. A matching request returns the existing event/transaction rather than creating another business effect.

### 14. Is a UUID encryption?

No. It is a record/reference identifier. It is not a secret or a replacement for authorisation.

### 15. What prevents negative stock?

The service checks available stock inside the transaction before deducting it. Invalid sales/transfers are rejected.

### 16. What is a transaction?

A group of changes that commit together or roll back together. It prevents a partial transfer or partial sale update.

### 17. Why keep both stock balances and movements?

Balances give the current quantity quickly. Movements explain how it reached that quantity and support investigation.

### 18. Why save the sale price separately from the product price?

Changing tomorrow's catalog price should not rewrite yesterday's recorded revenue.

### 19. Why store kobo rather than decimal money in PHP floats?

Integer minor units avoid floating-point rounding issues in the stored amount calculations.

### 20. How are staff restricted?

The server checks role, active status, session version and assigned branch. Directly changing a branch ID in a URL does not grant access.

### 21. What happens when a staff account is deactivated?

New login is blocked and existing sessions/remember tokens are invalidated. History is preserved. Reactivation requires fresh authentication.

### 22. Is an API key the same as an owner password?

No. It is a separate machine credential for a source assigned to one branch. Owners can rotate or revoke it.

### 23. Are API keys stored in readable form?

The source table stores their hashes. A newly created key is briefly available for one-time display through the owner's session, and local demo keys are written to a private development credential file.

### 24. What is the difference between an audit log and a stock ledger?

The audit log describes actions and actors. The stock ledger records quantity movements. They answer related but different questions.

### 25. Does a returned sale mean a payment was refunded?

No. The application restores stock and changes sales-reporting status. It does not execute bank or card refunds.

### 26. Does the application support independent businesses as tenants?

No. The current data model is one business with multiple branches.

### 27. What tests were performed?

Automated feature tests and browser checks of permissions, transactions, ingestion, sessions, inventory and reporting. At this documented build, 103 automated tests pass. Large-scale load and penetration tests have not been completed.

### 28. Does seeding mean real customers used the system?

No. Seeded data is generated demonstration data. It does not establish adoption, customer satisfaction or production reliability.

### 29. What would you improve next?

Cloud deployment, production database and load testing, WebSocket updates if needed, proper transfer-in-transit workflows, receipt replay protection, multi-line sales, partial returns and email account recovery.

### 30. Is the system ready for any business immediately?

It is a working development application that can demonstrate realistic workflows. A real deployment still needs operational setup, backup/restore verification, production testing and a business pilot.

## 23. What the student should be able to explain without reading

- The business problem and why branch data needs to be combined.
- The difference between a branch, product, stock balance, sale and movement.
- The actual technology stack and its purpose.
- MVC, services, middleware and background jobs in basic terms.
- What happens when a sale is recorded and when an API event is submitted.
- Why 202 is not completion and why a worker is needed.
- How revenue, units and stock alerts are calculated.
- How duplicate-request protection and a transaction work.
- What owners/staff can do.
- Why ten-second polling is near-real-time.
- Which parts are implemented and which parts still need deployment/testing.

A good defence explains one complete workflow accurately. Memorising framework names without understanding what a sale does to the database is less useful.

## 24. Production hosting direction

This is a future deployment outline, not a record of completed hosting.

1. Provision a compatible PHP hosting environment and chosen database.
2. Install locked dependencies and configure environment secrets.
3. Use an HTTPS web server whose document root is the application's `public` folder.
4. Set `APP_ENV=production`, `APP_DEBUG=false`, the real `APP_URL` and secure session cookies.
5. Apply migrations through a controlled deployment process.
6. Keep storage and bootstrap cache writable by the application user.
7. Run the queue worker under a process manager and restart workers after releases.
8. Set up application/worker monitoring and database/private-file backups.
9. Verify restoration, permissions and behaviour against the chosen production database.
10. Measure response time, throughput, error rate and queue delay under increasing workloads before making capacity claims.

Do not use the development `artisan serve` process as the final public production server. The [Laravel deployment guide](https://laravel.com/framework/docs/13.x/deployment) explains the framework's server setup requirements.

For the prepared Render configuration, see [Render deployment](RENDER_DEPLOYMENT.md). The default hosted demo uses temporary SQLite; optional PostgreSQL configuration is also supplied. No Render service has been deployed by preparation alone.

## 25. Documentation and references

The implementation files and tests are the primary source for claims about this particular application. Additional project guides provide more detailed contracts:

- [Account access](ACCOUNT_ACCESS.md)
- [Ingestion API](INGESTION_API.md)
- [Inventory operations](INVENTORY_OPERATIONS.md)
- [Sales reporting](SALES_REPORTING.md)
- [Defence revision sheet](DEFENCE_CHEAT_SHEET.md)

Official installation/framework references:

- [PHP installation](https://www.php.net/manual/en/install.php)
- [PHP on Windows](https://www.php.net/manual/en/install.windows.php)
- [Composer installation and introduction](https://getcomposer.org/doc/00-intro.md)
- [Laravel deployment and requirements](https://laravel.com/framework/docs/13.x/deployment)
- [Laravel queues](https://laravel.com/framework/docs/13.x/queues)
- [Livewire documentation](https://livewire.laravel.com/docs/4.x/installation)
- [Homebrew PHP 8.4](https://formulae.brew.sh/formula/php@8.4)

**Documentation boundary:** This guide describes the checked build on 13 November 2026. Test counts, dependency versions, features and installation steps should be reviewed again if the software changes.
