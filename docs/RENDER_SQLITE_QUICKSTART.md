# Render Free: SQLite school demonstration

This is the default deployment in `render.yaml`. No separate database, paid disk or paid worker is required. SQLite is a file inside the web container. Render Free can erase it on restart, redeploy or idle shutdown. All changes, accounts, imports and API keys can disappear. Startup creates a fresh database, owner and demonstration dataset when needed. Use this for assessment demonstrations; keep important records elsewhere.

## Deploy

1. In Render, choose **New → Blueprint** and connect `nahsir12390/Cloud-Based-Platform-for-Data-Aggregation-and-Real-Time-Analytics.`. Use the root `render.yaml` and the Free plan.
2. Enter the requested private environment settings: `APP_KEY`, `OWNER_EMAIL`, `OWNER_PASSWORD`, and `DEPLOY_DEMO_STAFF_PASSWORD`. Both passwords must be at least 12 characters, at most 72. Generate the key once on your computer with `php artisan key:generate --show`. Keep it stable across deploys. Never commit actual keys or passwords.
3. Apply the Blueprint. Docker installs PHP dependencies; startup migrates SQLite, creates the owner, loads sample records and starts Apache plus the ingestion worker.
4. Open the Render service URL and sign in with your configured owner email/password. Check `/api/health/ready`; it should return `{"status":"ok"}`.
5. Demonstrate branches, dashboard, inventory, sales, reports, CSV imports and source API keys. Sample staff use the password configured in `DEPLOY_DEMO_STAFF_PASSWORD`.

No build command, start command or PostgreSQL URL is needed when using this Docker Blueprint. Leave Root Directory blank: `artisan` and `Dockerfile` are at the repository root. Free services sleep after inactivity and may take time to wake. Open the service ahead of the presentation and retain a working local copy.

## Startup and reset behavior

`DB_CONNECTION=sqlite`, `DB_DATABASE=/var/www/html/storage/app/branchflow.sqlite`, and `DEPLOY_EPHEMERAL_SQLITE=true` select the temporary database explicitly. `DEPLOY_SEED_DEMO=true` enables the sample dataset. The startup script creates a missing database file, never truncates an existing one, and runs migrations. Existing owners and a previously seeded dataset are preserved while the database exists. After Render discards the file, configured credentials and sample data are recreated; later user edits are lost. API keys must then be rotated again in the application.

For a blank demonstration, set `DEPLOY_SEED_DEMO=false`; the owner is still created. For durable hosted data later, use the optional `render.postgres.yaml` configuration and a PostgreSQL provider. Database availability and free-plan allowances depend on that provider. Do not set a PostgreSQL `DB_URL` on the SQLite configuration.

## Troubleshooting

- Failed first deployment: inspect Render logs for missing owner credentials, invalid application key or invalid demo staff password. Update the environment settings and redeploy.
- Login stops working after a reset: use the original Render-configured owner credentials. Any password changes made only inside the old SQLite database have been lost.
- Missing records after restart: expected with temporary storage; this hosting choice does not provide backups or persistence.
- CSV uploads waiting: inspect the ingestion worker logs. Apache and the worker run in the same free container.

Storage limitations: https://render.com/docs/free
