# Optional PostgreSQL deployment on Render

**Default deployment now uses SQLite as requested. Start with [Render SQLite quickstart](RENDER_SQLITE_QUICKSTART.md). The instructions below describe the optional PostgreSQL alternative; use `render.postgres.yaml` wherever they refer to `render.yaml`. Production SQLite requires explicit `DEPLOY_EPHEMERAL_SQLITE=true`; the default SQLite Blueprint sets this.**

The prepared layout is **SQLite on your computer; PostgreSQL on the hosted app**. It is not automatic database failover. A failed PostgreSQL connection does not redirect hosted writes into SQLite.

No cloud service has been created by this preparation. You still need to connect your source repository, create/select the database and enter private environment values.

## 1. Why not SQLite on Render Free?

Render Free web services have temporary local storage. Restarts, redeploys and idle spin-down can discard a local SQLite database and uploaded files. Free instances do not support persistent disks.

Render's own Free PostgreSQL is limited to 1 GB and expires after 30 days. It is useful for a short demonstration, but is not an indefinitely free durable database. Free web services sleep after 15 minutes without inbound traffic and can take time to wake. These limits are documented in [Render's Free service guide](https://render.com/docs/free).

The supplied `render.yaml` creates **one free Docker web service**. You provide its PostgreSQL connection separately. A usage-limited external free PostgreSQL service such as [Neon Free](https://neon.com/docs/introduction/plans) avoids relying on Render's 30-day database trial. Review the provider's current allowances and choose its Free plan. Free services are appropriate for this school demonstration; they are not an uptime or unlimited-storage guarantee.

## 2. What was prepared

| File/change | Purpose |
| --- | --- |
| `Dockerfile` | PHP 8.4, Apache, PostgreSQL/SQLite drivers, production dependencies |
| `.dockerignore` | Excludes real environment files, credential files, SQLite data, uploads, dev dependencies and generated documents from the image |
| `docker/entrypoint.sh` | Validates port, clears stale config, runs the deployment command, caches config/views and starts Supervisor |
| `docker/supervisord.conf` | Runs Apache and one database queue worker inside the same container |
| `render.yaml` | Free Render web-service configuration; no paid worker, disk or database resource |
| `.env.render.example` | Safe template listing the private values to enter in Render |
| `branchflow:deploy` | Waits for the database, applies migrations and creates the first owner without resetting existing accounts |
| `/api/health/ready` | Stateless readiness check that returns 200 when the database responds, 503 otherwise |
| Durable CSV acceptance | Saves pending CSV content in PostgreSQL with its job so losing an upload file does not lose pending work |
| `.github/workflows/verify.yml` | Tests SQLite/PostgreSQL and builds the Docker image when the workflow runs in GitHub |

The separate worker is a **process**, not a separate Render service. It uses the same free web container's CPU/RAM. When Render stops the container, both processes stop. Pending jobs remain in PostgreSQL and resume when the service restarts. This arrangement is for a small demonstration, not independently scalable worker infrastructure.

## 3. Prepare the source repository

The simplest option is a repository whose root is the current `branchflow/platform` folder. Its top level should contain `artisan`, `composer.json`, `Dockerfile` and `render.yaml`.

Commit the source and `composer.lock`. Do not commit `.env`, `.local-access.md`, `.local-demo-access.md`, real API keys, existing SQLite databases or private uploaded files. The ignore files are prepared for this boundary. Inspect files before publishing a new repository; ignore rules do not remove secrets already present in Git history.

The prepared GitHub workflow assumes this application is the repository root. If using a repository with `branchflow/platform` or `platform` as a subfolder, adapt the workflow working directory and Docker build context accordingly.

For manual Render deployment from a parent repository, set Root Directory to the directory containing `artisan`. Use Dockerfile `./Dockerfile` relative to the selected app root. For a monorepo Blueprint, adjust `dockerfilePath` and `dockerContext` relative to the repository root, or use a dedicated app repository. See [Render's Blueprint reference](https://render.com/docs/blueprint-spec).

## 4. Create PostgreSQL on the chosen Free plan

Recommended demonstration path:

1. Create a Neon project on the Free plan.
2. Select a region reasonably close to the Render service. The supplied Blueprint uses Frankfurt; change it if another location is more suitable.
3. Open the database connection details.
4. Copy the PostgreSQL connection string. Start with the direct connection endpoint for this small deployment and migrations.
5. Keep `sslmode=require` and the connection credentials private.

The URL has a shape such as:

```text
postgresql://USER:PASSWORD@HOST/DATABASE?sslmode=require
```

This is a template, not a usable credential. Paste the provider's actual string into Render's `DB_URL` secret field.

An alternative is a **Render Postgres → Free** database in the same region, using its supplied connection URL. Remember its 30-day expiry, lack of free backups and storage limit. Do not select a paid database plan by mistake.

An empty PostgreSQL database does not automatically contain the records from your development SQLite file. You can seed an explicitly labelled hosted demo as described below. Migrating real existing SQLite data is a separate export/transform/import task; changing the connection setting does not transfer it.

## 5. Generate a stable application key

On your computer, from the application directory:

```bash
php artisan key:generate --show
```

Copy the complete generated `base64:...` value into Render's **APP_KEY** environment value. This prints a new key without replacing your local `.env` value. Use a separate key for a fresh hosted installation and keep it stable across future deploys.

Do not run `key:generate` on every startup and do not regenerate a key for an existing installation casually. Database session encryption and encrypted cookies depend on it.

## 6. Create the Render service

### Blueprint method

1. Open Render and choose **New → Blueprint**.
2. Connect the repository containing the prepared `render.yaml`.
3. Confirm the service plan is **Free**.
4. Fill in the secrets Render requests:

| Secret | What to enter |
| --- | --- |
| `APP_KEY` | The complete generated Laravel key |
| `DB_URL` | The PostgreSQL connection URL |
| `OWNER_EMAIL` | Email for the first hosted owner |
| `OWNER_PASSWORD` | Initial private password, 8–72 ASCII characters is a straightforward choice |

`OWNER_NAME` defaults to Business Owner and can be changed. First deployment creates an owner only if none exists. Later restarts preserve existing owner accounts and passwords, even if bootstrap values change. An existing non-owner with the bootstrap email is not silently promoted.

### Manual web-service method

Choose **New → Web Service**, connect the app repository, select **Docker**, choose **Free**, and use the supplied Dockerfile. Set the health-check path to:

```text
/api/health/ready
```

Enter the values shown in `.env.render.example` as Render environment variables. The main ones are:

```dotenv
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=pgsql
DB_URL=YOUR_PRIVATE_POSTGRES_URL
DB_SSLMODE=require
DB_TIMEZONE=Africa/Lagos
FORCE_HTTPS=true
TRUST_PROXIES=true
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
CACHE_STORE=database
QUEUE_CONNECTION=database
LOG_CHANNEL=stderr
```

Also enter the key and owner credentials. Do not put real values into committed example files.

`APP_URL` can be omitted: the application uses Render's `RENDER_EXTERNAL_URL` when `APP_URL` is absent. For a custom domain, set `APP_URL` explicitly to its HTTPS address and redeploy.

No npm build command is required. No paid pre-deploy command, dashboard shell, persistent disk, Redis service or separate Render worker is required for this configuration. Startup runs the migration/bootstrap command because free Render services do not provide all paid deployment/shell features.

## 7. Optional hosted school demo data

For a fresh school-demo database, add these environment variables **before the first deployment**:

```dotenv
DEPLOY_SEED_DEMO=true
DEPLOY_DEMO_STAFF_PASSWORD=YOUR_PRIVATE_DEMO_PASSWORD
```

Use a valid 8+ character password within the application's byte limit. The three demo staff accounts use this configured password:

- `demo.wuse@branchflow.test`
- `demo.garki@branchflow.test`
- `demo.kubwa@branchflow.test`

This is an explicit school-demo option. It stays off by default. Do not enable it in a database intended for actual business records.

Production demo seeding requires the opt-in and demo password. Startup checks the demo audit marker before seeding, so routine restarts do not repeatedly add demo data. Initial demo seeding may make the first boot slower.

The hosted app does not rely on a local demo credential file. To obtain a demo integration key, sign in as owner and rotate that source's key in Integrations, then save its one-time displayed value privately.

After verifying the hosted demo, set `DEPLOY_SEED_DEMO=false`. You may remove the initial owner password variable after confirming an owner exists. Keep it available privately if you later deliberately connect a completely empty database; bootstrap will refuse to start without valid first-owner credentials in an empty installation.

Owner password changes happen in My account, not by editing the startup password environment value.

## 8. What happens when the container starts

1. Checks the application key and production database/debug settings.
2. Waits for PostgreSQL availability, with bounded retries.
3. Runs pending migrations; it does not run `migrate:fresh`.
4. Creates the first owner only when needed.
5. Seeds labelled demo data only when explicitly enabled and not already marked installed.
6. Builds configuration and Blade caches.
7. Starts Apache on Render's `PORT` and the queue worker under Supervisor.

The image defaults to PostgreSQL in production. The deployment command rejects production SQLite instead of silently accepting an unsafe ephemeral database. Local `.env.example` continues to use SQLite.

## 9. Persistence and CSV imports

Sales, stock, sessions, cache/locks, source credentials and pending jobs are stored in PostgreSQL.

New CSV uploads also save their validated UTF-8 contents in the database while pending. The import record and its queue job commit together using the application database. The worker can process from this content even if the original local file disappears. Successful processing clears the stored content to reduce database usage; the import result/error metadata remains. Failed processing retains content for investigation/retry.

Legacy uploads created before this change have no database content. If their old file is lost, re-upload the original CSV with the same references; replay checks prevent accepted matching transactions from deducting stock twice.

The temporary filesystem is not used for durable uploaded-file retention. This deployment does not preserve arbitrary future images/documents; those would need external object storage. Keep failed import payloads and log/session/cache growth within your database provider's free allowance.

## 10. Verification after deploy

1. Wait for Render to report a healthy deployment.
2. Open `/api/health/ready`; expect `{"status":"ok"}`.
3. Sign in using the hosted owner account.
4. Confirm branches/products, or create them if demo seeding is disabled.
5. Record a small test sale against available stock.
6. Upload a valid CSV and check that it leaves queued status without manually starting another service.
7. Submit an API sale and check its status endpoint.
8. Confirm the reported status URL and browser links use HTTPS.
9. Restart/redeploy once and confirm the same records and owner login remain.
10. Inspect Render logs for queue/Apache errors and generic readiness failures.

The health endpoint does not expose credentials or start a database-backed browser session. It checks database connectivity, not continuous worker health. Inspect ingestion queue delays/statuses and process logs as well.

## 11. Local checks and CI

Local SQLite remains the standard development setup:

```bash
composer install
php artisan migrate
php artisan test --compact
```

Do not run the production deployment command against your regular SQLite installation with `APP_ENV=production`; the safety check rejects it deliberately.

To exercise the production image on a computer with Docker and a separate test PostgreSQL database:

```bash
docker build -t branchflow-render .
docker run --rm --env-file .env.render.local -p 10000:10000 branchflow-render
```

`.env.render.local` must contain valid test deployment secrets. It is ignored by Git. Docker containers cannot reach your host's PostgreSQL through the container's own `127.0.0.1`; use the appropriate host gateway or another container's network name. This command does not expose/mount your SQLite file.

The GitHub workflow runs the test suite against SQLite and PostgreSQL 16 and builds the Docker image. It assumes the application is the repository root and uses GitHub's test-only PostgreSQL service credentials, not deployment credentials.

## 12. Free-tier limits and troubleshooting

- **First request is slow:** the free service/database may be waking from idle. Do not promise always-on hosting.
- **Database disappears after 30 days:** this applies to Render's own Free Postgres. Use the external provider option or back up before expiry; SQLite does not fix it.
- **No queue progress:** inspect the Supervisor/queue logs. The worker lives inside the web service and sleeps when Render stops that container.
- **Out of free usage:** Render hours are shared across free services in the workspace; external database compute/storage/egress have separate allowances. Do not add artificial uptime pings to pretend the service is always on.
- **Startup fails:** check `APP_KEY`, `DB_URL`, SSL, owner bootstrap settings and database availability. Sensitive connection strings are not printed by the bootstrap/readiness checks.
- **Login fails after a deploy:** verify that `APP_KEY` did not change, sessions still point to the same PostgreSQL database, and the account is active.
- **Cloud data does not match local data:** separate databases do not synchronise automatically.
- **Want to keep important records:** maintain a protected database backup and test restoring it. Free-tier settings do not replace backups.

Render's current free-plan restrictions and provider plans may change. Review the official pages before creating resources. Preparation and local database tests are not proof of a successful hosted deployment or load-test capacity.
