# Branch ingestion API

This API accepts sales from external branch software. It does not connect to any POS vendor automatically. A sender must implement this contract. Each request is one product line, matching the current sale model.

## Create a source

1. Sign in as an owner and open **Integrations**.
2. Choose the source name and its assigned branch.
3. Create the source and copy the key shown once. Only its SHA-256 hash is retained in the source table. The plaintext key is temporarily flashed into the owner's session for that one response; the response is marked `no-store`.
4. Configure the sending system to keep this key private. Browser frontend code must not embed it. Use HTTPS outside local development.

Keys have the format `bf_` followed by 64 random hexadecimal characters. Owners can rotate or revoke keys. Rotation immediately invalidates the old key but preserves source identity and event history. Revocation blocks new API submissions and status reads. Previously accepted events still process.

## Start processing

From the Laravel application directory:

```bash
php artisan migrate
php artisan queue:work ingestion --tries=3 --timeout=120
```

The `ingestion` queue connection deliberately uses the application's default database and `jobs` table. It does not inherit `DB_QUEUE_CONNECTION`. Ingestion and its queue row are inserted inside the same database transaction. This avoids accepting an event without scheduling it when a process exits after commit.

New CSV uploads retain their pending contents in the application database until successful processing, so losing a temporary upload file does not lose accepted work.

The default configuration also lets this worker process CSV imports because both use the `default` queue in the same database. If you move CSV jobs to another connection, run a separate worker there. Keep database queue visibility/retry timeouts longer than job execution timeouts; the supplied database connections default to 180 seconds. Restart long-running workers after source changes (`php artisan queue:restart`).

## Submit a sale

`POST /api/v1/sales`

Headers:

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
  "sold_at": "2026-11-12T10:30:00+01:00"
}
```

- `reference`: globally unique UUID; keep it unchanged for retries.
- `sku`: exact existing catalog SKU.
- `quantity`: integer from 1 through 1,000,000.
- `sold_at`: valid timestamp with whole seconds and an explicit `Z` or `±HH:MM` offset; must not be in the future. Fractional seconds are not currently accepted. Timestamps are canonicalized to UTC for comparison and converted to the application timezone for storage.
- Branch comes from the API source. Supplying a nonempty `branch_id`, `unit_price_minor`, or `total_minor` is rejected. Prices come from the catalog at processing time. This endpoint does not support historical external prices.
- Maximum request body: 16 KB.

Successful acceptance returns **202**, not a completed sale:

```json
{
  "data": {
    "id": "9bb38a00-1ec2-4f12-844d-c2e910d09205",
    "reference": "550e8400-e29b-41d4-a716-446655440000",
    "status": "queued",
    "sale_id": null,
    "error": null,
    "received_at": "2026-11-12T10:31:00+01:00",
    "processed_at": null,
    "status_url": "http://127.0.0.1:8000/api/v1/sales/550e8400-e29b-41d4-a716-446655440000"
  },
  "duplicate": false
}
```

Stock is deducted only once the worker successfully records the sale. A transaction must not be treated as financially settled merely because this endpoint accepted it; this application does not process payments.

## Check status

`GET /api/v1/sales/{reference}` with the same source key. This returns **200** for an existing event, including queued events.

| Status | Meaning | Sender action |
| --- | --- | --- |
| `queued` | Accepted, awaiting processing or an automatic retry | Poll with a delay |
| `completed` | Sale and stock movement committed | Store the returned sale ID |
| `rejected` | Business validation failed, such as insufficient stock or a conflicting existing sale | Show the reason; owner can resolve the problem and retry |
| `failed` | A processing exception exhausted automatic retries | Owner investigates and retries |

`processed_at - received_at` includes queue waiting time. It is not an isolated database processing benchmark. The Integrations page displays counts, source request activity, reasons, latency and a notice when queued events are older than two minutes. Last request means the last accepted POST or matching replay, not an independent health heartbeat.

## Idempotency and errors

A matching repeat request from the same source returns the existing event and `duplicate: true`. A queued replay returns 202; a terminal replay returns 200. Replays do not schedule another job or deduct stock again. Equivalent timestamp offsets representing the same instant count as a matching payload. UUID reference casing is normalized.

Reusing an API reference with different data, or from another source, returns **409**. Owner retries reuse the event's stored payload and original reference. Rejected/failed events can be retried; queued/completed ones cannot. A completed event records the ingestion outcome; later returning its sale does not change that historical outcome.

| HTTP code | Meaning |
| --- | --- |
| 401 | Missing, invalid, rotated, or revoked key |
| 404 | Unknown reference or reference owned by another source |
| 409 | Reference conflict or invalid owner retry/rotation |
| 413 | Body too large |
| 415 | Submit JSON using the correct content type |
| 422 | Payload validation error |
| 429 | Rate limit; follow `Retry-After` |

Source limit is 120 requests per minute, shared between submission and status calls. There is also a 600/minute per-IP edge limit. Back off when polling. For network failures and transient server errors, retry with the **same** reference and payload. Do not create a new reference merely to work around a timeout.

## Scope and operational limits

This is a branch ingestion foundation, tested locally with SQLite. Production deployment, concurrency verification on MySQL/PostgreSQL, credential lifecycle policy, vendor-specific connectors and measured load testing remain. Direct POS integration and payment processing are not included. Source and event histories are retained; retention and archival policies have not yet been implemented.
