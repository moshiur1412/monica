# Monica CRM — Reliable Background Import System

## Assignment Overview

Monica allows users to import contacts from CSV or vCard files. The current Monica v3 codebase (rewrite) had **no CSV import implementation** — it only supported contact import via the CardDAV protocol (vCard format) through sabre/dav integration. This project redesigns the import system end-to-end: from file upload to background processing, with progress tracking, error handling, and observability.

---

## 1. Problem Analysis

### Key Files Investigated

| File                                                                                 | Status            | Notes                                          |
| ------------------------------------------------------------------------------------ | ----------------- | ---------------------------------------------- |
| `app/Http/Controllers/ImportController.php`                                          | **Did not exist** | No import controller existed                   |
| `app/Http/Controllers/ApiController.php`                                             | Exists            | Base API controller used as parent             |
| `app/Models/Contact.php`                                                             | Exists            | Contact model with vCard sync fields           |
| `app/Models/User.php`                                                                | Exists            | User model with account/vault relationships    |
| `app/Models/Vault.php`                                                               | Exists            | Vault organizational unit for contacts         |
| `app/Domains/Contact/Dav/Services/ImportVCard.php`                                   | Exists            | **Only existing import logic** — vCard via DAV |
| `app/Domains/Contact/Dav/Jobs/UpdateVCard.php`                                       | Exists            | Queued vCard import job (single contact)       |
| `app/Domains/Contact/ManageContact/Services/CreateContact.php`                       | Exists            | Contact creation service (reused)              |
| `app/Domains/Contact/ManageContactInformation/Services/CreateContactInformation.php` | Exists            | Contact info service (reused)                  |
| `routes/api.php`                                                                     | Exists            | No import routes existed                       |
| `database/migrations/`                                                               | Exists            | No `import_jobs` table existed                 |
| `tests/`                                                                             | **Did not exist** | No test infrastructure existed                 |

### Current Import Flow (CardDAV-only)

The only existing import path is through the CardDAV protocol. There was **no file upload, no CSV parsing, no batch processing**.

```
External DAV Client (Apple Contacts, Thunderbird, etc.)
    │
    │  PUT /dav/addressbooks/user/vaultname/contact.vcf
    ▼
CardDAVBackend::createCard() / updateCard()
    │
    │  Dispatches UpdateVCard job (queued)
    ▼
UpdateVCard::execute()
    │
    │  Calls ImportVCard::execute() with vCard string
    ▼
ImportVCard::execute()
    │
    │  1. Parse vCard via sabre/vobject
    │  2. Check which importers handle this kind
    │  3. Run importers in priority order:
    │     - ImportContact (Order 1)    → CreateContact service
    │     - ImportGroup (Order 10)     → Create/modify groups
    │     - ImportMembers (Order 11)   → Manage group members
    │     - ImportAddress (Order 40)   → Create addresses
    │     - ImportLabels (Order 40)    → Manage labels
    │     - ImportContactInformation (Order 40) → Email/phone/URLs
    │     - ImportImportantDates (Order 40) → Birthdates
    ▼
Contact created/updated with vCard stored in contacts.vcard
```

**Key observations:**

- Each `UpdateVCard` job processes exactly **one** vCard — no batching
- No CSV format support at all
- No user-facing upload mechanism (file upload UI/API)
- No progress tracking
- No error reporting to users
- No import history/audit trail
- Zero test coverage

### Existing Codebase Issues

1. **Mixed primary key strategy**: `contacts` uses UUIDs (`HasUuids`), while `contact_information`, `addresses`, and `labels` use auto-increment `bigint`. The newer migration added a separate `uuid` column to date/task tables, creating a dual-key system.

2. **vCard storage bloat**: Raw vCard data is stored in `contacts.vcard` (`mediumText`). For contacts with photos, this can be very large and is stored on every sync.

3. **Auto-creation of reference data**: The DAV importers auto-create `Gender`, `ContactInformationType`, and `AddressType` entries if they don't exist, which can pollute reference tables with arbitrary values.

4. **No duplicate contact detection**: The existing import only matches by `distant_uri` or UUID. If the same person is imported from different sources, duplicates will be created.

5. **Fragile name parsing**: `ImportContact::importNameFromFN()` splits on whitespace, which breaks for multi-word last names like "Van Gogh" or "De La Cruz".

---

## 2. Database Design: `import_jobs` Table

### Schema

| Column                      | Type                                                            | Justification                                                                                                                                                                                                                                                                                                                   |
| --------------------------- | --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`                        | UUID (PK)                                                       | Consistent with Monica's UUID convention for primary keys. Enables distributed ID generation without collision, important for future sharding.                                                                                                                                                                                  |
| `account_id`                | UUID (FK → accounts)                                            | **Tenant sharding key.** Every Monica instance can have multiple accounts. All queries filter by `account_id` for data isolation. Enables future partition pruning.                                                                                                                                                             |
| `user_id`                   | UUID (FK → users)                                               | **Import ownership.** The user who initiated the import. Used for authorization scoping (users can only see their own imports). Enables per-user import history.                                                                                                                                                                |
| `vault_id`                  | UUID (nullable FK → vaults)                                     | **Target vault.** Where imported contacts are created. Nullable to support future cross-vault imports or alternative destinations. Required for `CreateContact` service which needs `vault_id`.                                                                                                                                 |
| `filename`                  | `string(255)`                                                   | **Original filename.** Preserved for user display in the import list UI. Useful for identifying which file was uploaded.                                                                                                                                                                                                        |
| `original_file_path`        | `string(500)`                                                   | **Storage path.** Enables re-reading the file for: (a) batch processing by chunked jobs, (b) error CSV reconstruction (re-reading original row data). Stored via Laravel's `Storage` facade, so path is relative to disk root — allows swapping local storage for S3 without code changes.                                      |
| `file_hash`                 | `string(64)`, indexed                                           | **Duplicate detection.** MD5 hash of file content. Indexed for efficient lookups. Current policy allows duplicates (user may re-upload a corrected file), but the hash enables future "skip duplicate" or "warn on duplicate" policies.                                                                                         |
| `total_rows`                | `integer`, default 0                                            | **Total row count.** Set after CSV parsing in `ProcessImportJob`. Used for progress percentage calculation. Zero initially because we don't parse during the HTTP request (to stay under 500ms).                                                                                                                                |
| `processed_rows`            | `integer`, default 0                                            | **Successfully processed count.** Incremented atomically by each batch job via `UPDATE ... SET processed_rows = processed_rows + N`. Used for progress_pct and ETA calculation.                                                                                                                                                 |
| `failed_rows`               | `integer`, default 0                                            | **Failed row count.** Incremented atomically alongside `processed_rows`. Enables failure rate monitoring (`failed_rows / total_rows`).                                                                                                                                                                                          |
| `status`                    | `enum('pending','processing','completed','failed','cancelled')` | **Import lifecycle state.** Drives all business logic: batches check this flag for cancellation, monitoring queries detect stuck imports, API filters by status.                                                                                                                                                                |
| `errors`                    | `JSON` (nullable)                                               | **Per-row error collection.** Array of `{row: int, message: string}` objects. Stored as JSON for flexibility. **Trade-off**: As the error count grows, querying/updating this column becomes expensive. At scale, migrate to a separate `import_job_errors` table. Limited to first 10 errors in API responses for performance. |
| `started_at`                | `timestamp` (nullable)                                          | **Processing start time.** Set when `ProcessImportJob` begins. Used for ETA calculation: `rate = processed_rows / elapsed_seconds`, `remaining = (total - processed) / rate`. Also used for stuck import detection.                                                                                                             |
| `completed_at`              | `timestamp` (nullable)                                          | **Completion timestamp.** Set when batch `then()` callback fires. Used for: (a) average processing time metrics, (b) import history display.                                                                                                                                                                                    |
| `cancelled_at`              | `timestamp` (nullable)                                          | **Cancellation timestamp.** Set when user calls `POST /api/import/:id/cancel`. Distinct from `completed_at` so monitoring can distinguish user-initiated cancellations from system failures.                                                                                                                                    |
| `batch_size`                | `integer`, default 50                                           | **Rows per batch.** Stored per-import rather than hardcoded so it can be tuned per import (e.g., smaller batches for complex contact data, larger for simple). Enabled by a future admin setting or header parameter.                                                                                                           |
| `created_at` / `updated_at` | `timestamp`                                                     | Standard Laravel timestamps.                                                                                                                                                                                                                                                                                                    |

### Indexes

| Index                        | Purpose                                                    |
| ---------------------------- | ---------------------------------------------------------- |
| `PRIMARY KEY (id)`           | Fast lookup by ID for API show/cancel/errors               |
| `INDEX (account_id, status)` | Tenant-scoped status queries (monitoring, stuck detection) |
| `INDEX (user_id, status)`    | User-scoped import history listing                         |
| `INDEX (created_at)`         | Paginated list ordering                                    |
| `INDEX (file_hash)`          | Duplicate file lookups                                     |

---

## 3. Queue Architecture & Batch Processing

### Why Queue Processing?

The assignment requires the HTTP endpoint to respond within **500ms**. Parsing a CSV with potentially thousands of rows and creating contacts would take seconds or minutes synchronously. By dispatching a queue job:

1. HTTP endpoint validates → stores file → creates DB record → dispatches job → returns 201
2. Queue worker processes the file asynchronously in the background
3. Progress is tracked via the `import_jobs` record

### Queue Driver Setup

The system uses Laravel's queue with the **database** driver. Three environments are involved:

| Environment | Driver                | Configuration                                                   |
| ----------- | --------------------- | --------------------------------------------------------------- |
| Development | `database`            | `.env`: `QUEUE_CONNECTION=database`                             |
| Testing     | `sync`                | `phpunit.xml`: `<env name="QUEUE_CONNECTION" value="sync"/>`    |
| Production  | `database` or `redis` | `.env`: `QUEUE_CONNECTION=database` or `QUEUE_CONNECTION=redis` |

The required queue tables (`jobs`, `job_batches`, `failed_jobs`) are created by Laravel's default migrations and already exist in this codebase.

**Important:** In development, you must run a queue worker for imports to process:

```bash
php artisan queue:work
```

Without the worker, the import will remain in `pending` status indefinitely. Tests use `QUEUE_CONNECTION=sync` so they run deterministically without a queue worker.

### Batching Strategy

**Chosen batch size: 50 rows per batch**

Alternatives considered:

| Approach                   | Pros                                                                | Cons                                                                              |
| -------------------------- | ------------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| **50 rows/batch (chosen)** | Memory ~500KB/batch, granular progress updates, limited retry scope | More queue jobs per import                                                        |
| **Entire file in one job** | Simple, single job per import                                       | Memory exhaustion on large files, no partial progress, single point of failure    |
| **One job per row**        | Maximum parallelism, fine-grained retry                             | Massive queue overhead (10,000 jobs for 10,000 rows), DB update contention        |
| **Streaming (generator)**  | No memory buffer                                                    | Hard to implement retry/cancellation at row level, requires persistent connection |

**Justification for 50:** A batch of 50 rows typically completes in <5 seconds. If a batch fails (e.g., database connection issue), only 50 rows need to be retried rather than the entire file. The progress update granularity (2% per batch for a 2500-row file) is sufficient for meaningful progress bars.

### Failure Propagation

- **If a batch job crashes** (PHP fatal error, OOM, etc.): Laravel's `Bus::batch` `catch()` callback fires, setting import status to `failed`. Remaining queued batches execute the `failed()` method but detect the import is already failed and exit early.
- **If a single row fails** (validation error, duplicate, etc.): The error is recorded in `import_jobs.errors`, `failed_rows` is incremented, and the batch continues processing remaining rows.
- **If ALL rows fail**: The `then()` callback detects `failed_rows === total_rows` and sets status to `failed` explicitly.

### Concurrency Model

Batch jobs use `DB::transaction` with `lockForUpdate()` when updating the `import_jobs` record:

```php
DB::transaction(function () use ($importJob, $processedInBatch, $failedInBatch, $batchErrors) {
    $job = ImportJob::lockForUpdate()->findOrFail($importJob->id);
    $job->processed_rows += $processedInBatch;
    $job->failed_rows += $failedInBatch;
    // ...
    $job->save();
});
```

This creates a row-level lock in MySQL, preventing race conditions when multiple batches complete simultaneously. The `processed_rows += N` pattern is atomic even without the lock.

### One Queue vs Per-Import Queues

**Decision:** Single queue (`imports`).

**Rationale:** Per-import queues would require dynamic queue configuration and complicate worker management. A single import queue with clear job IDs is simpler to monitor and debug. At 10× scale, dedicated queue workers for the `imports` queue can be provisioned without code changes.

---

## 4. Development Setup

### Prerequisites

- PHP 8.3+
- MySQL 8.0+
- Composer dependencies installed
- Laravel queue tables migrated (already present)

### Environment Configuration

Ensure these values in `.env`:

```ini
QUEUE_CONNECTION=database
DB_CONNECTION=mysql
```

The `QUEUE_CONNECTION=database` setting is critical — with `sync`, jobs execute immediately during the HTTP request, violating the <500ms upload target.

### Quick Start

```bash
# Terminal 1: Start queue worker
php artisan queue:work

# Terminal 2: Get a test token
curl http://localhost/get-token
# → {"token":"...","vault_id":"..."}

# Upload a CSV for import
curl -X POST http://localhost/api/import \
  -H "Authorization: Bearer <token>" \
  -F "file=@contacts.csv" \
  -F "vault_id=<uuid>"
# → 201 Created, status "pending"

# Poll status (repeat until "completed")
curl http://localhost/api/import/<id> \
  -H "Authorization: Bearer <token>"
```

### Test Token Endpoint

A convenience endpoint `GET /get-token` returns a valid Sanctum token and vault ID for the first user in the database. This is provided for quick API exploration during development and interviews.

**Response** `200 OK`

```json
{
  "token": "1|abc123...",
  "vault_id": "01J0X1Y2Z3..."
}
```

**Errors**

| Status | Condition                                         |
| ------ | ------------------------------------------------- |
| 404    | No users found. Run migrations and seeders first. |

---

## 5. API Reference

All endpoints require **Bearer token** authentication (`Authorization: Bearer <token>`).
Endpoints that create or cancel imports require the `write` ability; read-only endpoints require `read`.

### `POST /api/import` — Upload CSV and start import

Upload a CSV or vCard file for background processing. The file is stored to disk, an `import_jobs` record is created, and a queue job is dispatched to parse and process it asynchronously.

**Request** (`multipart/form-data`)

| Param      | Type     | Required | Description                                                  |
| ---------- | -------- | -------- | ------------------------------------------------------------ |
| `file`     | `file`   | Yes      | CSV (`.csv`, `.txt`) or vCard (`.vcf`, `.vcard`). Max 100MB. |
| `vault_id` | `string` | Yes      | UUID of the vault to import contacts into.                   |

**Response** `201 Created`

```json
{
  "data": {
    "id": "01J0X1Y2Z3...",
    "filename": "contacts.csv",
    "total_rows": 0,
    "processed_rows": 0,
    "failed_rows": 0,
    "status": "pending",
    "progress_pct": 0,
    "errors": [],
    "started_at": null,
    "completed_at": null,
    "estimated_remaining_sec": null,
    "created_at": "2026-06-05T12:00:00Z",
    "updated_at": "2026-06-05T12:00:00Z"
  }
}
```

**Errors**

| Status | Condition                  |
| ------ | -------------------------- |
| 422    | Missing file or vault_id   |
| 422    | Invalid vault_id UUID      |
| 422    | vault_id not found         |
| 422    | File exceeds 100MB         |
| 422    | File MIME type not allowed |

---

### `GET /api/import` — List imports

Returns a paginated list of imports for the authenticated user, newest first.

**Request** (query params)

| Param      | Type      | Default | Description                 |
| ---------- | --------- | ------- | --------------------------- |
| `page`     | `integer` | 1       | Page number.                |
| `per_page` | `integer` | 15      | Results per page (max 100). |

**Response** `200 OK`

```json
{
  "data": [
    {
      "id": "01J0X1Y2Z3...",
      "filename": "contacts.csv",
      "total_rows": 1500,
      "processed_rows": 750,
      "failed_rows": 3,
      "status": "processing",
      "progress_pct": 50,
      "errors": [
        { "row": 12, "message": "Invalid email: 'bad'" },
        { "row": 45, "message": "Missing required field: name" },
        { "row": 88, "message": "Invalid email: 'foo'" }
      ],
      "started_at": "2026-06-05T11:55:00Z",
      "completed_at": null,
      "estimated_remaining_sec": 45,
      "created_at": "2026-06-05T11:54:00Z",
      "updated_at": "2026-06-05T11:55:30Z"
    }
  ],
  "links": {
    "first": "http://localhost/api/import?page=1",
    "last": "http://localhost/api/import?page=5",
    "prev": null,
    "next": "http://localhost/api/import?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "per_page": 15,
    "to": 15,
    "total": 72
  }
}
```

**Notes**

- `errors` field is only included when status is `processing`, `completed`, or `failed`. Limited to first 10 errors.
- `estimated_remaining_sec` is only included when status is `processing` and at least one row has been processed. Calculated as `(total_rows - processed_rows) / (processed_rows / elapsed_seconds)`.

---

### `GET /api/import/{id}` — Get import status

Returns detailed status of a single import job.

**Request** (path param)

| Param | Type     | Description             |
| ----- | -------- | ----------------------- |
| `id`  | `string` | UUID of the import job. |

**Response** `200 OK`

```json
{
  "data": {
    "id": "01J0X1Y2Z3...",
    "filename": "contacts.csv",
    "total_rows": 500,
    "processed_rows": 500,
    "failed_rows": 0,
    "status": "completed",
    "progress_pct": 100,
    "errors": [],
    "started_at": "2026-06-05T11:55:00Z",
    "completed_at": "2026-06-05T11:57:12Z",
    "estimated_remaining_sec": null,
    "created_at": "2026-06-05T11:54:00Z",
    "updated_at": "2026-06-05T11:57:12Z"
  }
}
```

**Errors**

| Status | Condition                                             |
| ------ | ----------------------------------------------------- |
| 404    | Import job not found, or belongs to a different user. |

---

### `POST /api/import/{id}/cancel` — Cancel an import

Marks a running import as cancelled. Batch jobs that haven't started yet will skip; the currently running batch will check the flag between rows and stop gracefully.

**Request** (path param)

| Param | Type     | Description             |
| ----- | -------- | ----------------------- |
| `id`  | `string` | UUID of the import job. |

**Response** `200 OK`

```json
{
  "data": {
    "id": "01J0X1Y2Z3...",
    "status": "cancelled",
    "cancelled_at": "2026-06-05T12:05:00Z",
    "processed_rows": 230,
    "total_rows": 500,
    "progress_pct": 46,
    ...
  }
}
```

**Errors**

| Status | Condition                                                                     |
| ------ | ----------------------------------------------------------------------------- |
| 404    | Import job not found, or belongs to a different user.                         |
| 422    | Import is not currently processing (already completed, failed, or cancelled). |

---

### `GET /api/import/{id}/errors` — Paginated row errors

Returns per-row errors for a completed or failed import, with pagination.

**Request** (path + query)

| Param      | Type      | Default | Description                    |
| ---------- | --------- | ------- | ------------------------------ |
| `id`       | `string`  | —       | UUID of the import job (path). |
| `page`     | `integer` | 1       | Page number.                   |
| `per_page` | `integer` | 10      | Results per page (max 100).    |

**Response** `200 OK`

```json
{
  "data": [
    { "row": 2, "message": "Missing required field: name" },
    { "row": 5, "message": "Invalid email: 'bad@'" },
    { "row": 7, "message": "Phone number is required" }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 10,
    "total": 3,
    "last_page": 1
  }
}
```

**Errors**

| Status | Condition                                             |
| ------ | ----------------------------------------------------- |
| 404    | Import job not found, or belongs to a different user. |

---

### `GET /api/import/{id}/errors.csv` — Download error CSV

Downloads the original file with an appended `error` column. The error column contains the error message for each row (or is blank for successful rows). The file is reconstructed on-the-fly from the stored original file — no row data is persisted in the database.

**Request** (path param)

| Param | Type     | Description             |
| ----- | -------- | ----------------------- |
| `id`  | `string` | UUID of the import job. |

**Response** `200 OK` — CSV file download

```
name,email,phone,error
John Doe,john@example.com,+1234567890,
,Bad Row,,Missing required field: name
Jane,invalid-email,+9876543210,Invalid email: 'invalid-email'
```

Headers include:

| Header                | Value                                        |
| --------------------- | -------------------------------------------- |
| `Content-Type`        | `text/csv`                                   |
| `Content-Disposition` | `attachment; filename="contacts_errors.csv"` |

**Errors**

| Status | Condition                                             |
| ------ | ----------------------------------------------------- |
| 404    | Import job not found, or belongs to a different user. |
| 404    | Original file has been deleted from storage.          |
| 500    | Cannot read or parse the original file.               |

---

### Progress Calculation

```php
progress_pct = (processed_rows / total_rows) × 100

estimated_remaining_sec = (total_rows - processed_rows) / (processed_rows / elapsed_seconds)
```

Progress is computed from the `import_jobs` table directly — no JOINs on contact tables — guaranteeing <50ms reads even at scale.

### Error CSV Reconstruction Logic

The error CSV is built without storing every row's data in the database:

1. **Re-read the original uploaded file** from `import_jobs.original_file_path`
2. **Parse headers** from the first line
3. **Build an error map** keyed by row number from `import_jobs.errors` JSON
4. **Iterate data rows**, appending the error message (or blank) as an additional column

```php
$errors = $importJob->errors; // [{row: 2, message: "..."}, {row: 5, message: "..."}]
$errorMap = collect($errors)->groupBy('row')->map(fn($items) => $items->pluck('message')->implode('; '));

$content = Storage::disk('local')->get($importJob->original_file_path);
$lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
$lines = array_filter($lines, fn ($line) => trim($line) !== '');
$headers = str_getcsv(array_shift($lines));

$csv = fopen('php://temp', 'r+');
fputcsv($csv, array_merge($headers, ['error']));

foreach ($lines as $rowNum => $line) {
    $row = str_getcsv($line);
    $data = [];
    foreach ($headers as $i => $header) {
        $data[$header] = $row[$i] ?? '';
    }
    $data['error'] = $errorMap->get($rowNum + 1, '');
    fputcsv($csv, $data);
}
```

**Why this approach?**

- Does **not** require storing all row data in the database
- Works with any file format (CSV, vCard)
- Original file is the definitive source of truth for row data
- Trade-off: requires the original file to remain accessible

**Edge case:** If the original file is deleted (e.g., retention policy), the error CSV endpoint returns 404. A production improvement would archive original files for a configurable retention period.

---

## 6. Error Handling & Isolation

### Per-Row Error Handling

Within each batch job, every row is processed in a try/catch block:

```php
foreach ($batchRows as $index => $row) {
    $rowNumber = $this->offset + $index + 1;

    try {
        $normalized = $parser->normalizeRow($row);
        $validationErrors = $parser->validateRow($normalized);

        if (!empty($validationErrors)) {
            $failedInBatch++;
            continue;
        }

        $importer->import($normalized, $this->context);
        $processedInBatch++;
    } catch (\Exception $e) {
        $failedInBatch++;
    }
}
```

### Status Rules

| Scenario                      | Final Status |
| ----------------------------- | ------------ |
| All rows succeed              | `completed`  |
| Some rows fail, some succeed  | `completed`  |
| All rows fail                 | `failed`     |
| Batch job crashes (exception) | `failed`     |
| User cancels                  | `cancelled`  |

---

## 7. Concurrency, Idempotency & Recovery

### Q: What happens if the user uploads the same file twice?

**Current behavior:** Both uploads proceed independently. Each gets a unique `import_jobs` record and both process to completion.

**Duplicate detection mechanism:** The `file_hash` column stores an MD5 hash of the file content. A future enhancement could check for recent imports (last 24h) with the same hash:

```php
$existing = ImportJob::where('file_hash', $hash)
    ->where('user_id', $userId)
    ->where('created_at', '>', now()->subDay())
    ->whereIn('status', ['pending', 'processing', 'completed'])
    ->exists();
```

**Design choice:** We do NOT block duplicates by default because:

- The user may have corrected errors in the original file
- The user may want to import the same contacts into a different vault
- False positives are possible (different files with same hash — extremely unlikely but possible with MD5)

**Recommendation:** Return a warning in the API response if a duplicate is detected, but allow the import to proceed.

### Q: What happens if a batch job crashes mid-way?

**Scenario:** A batch job updates `processed_rows` but crashes before the DB transaction commits. The row-level lock is released, and the batch never completes.

**Recovery mechanism:** Detecting stuck batches requires the import-level stuck detection:

```sql
-- Detect imports stuck in 'processing' for >30 minutes
SELECT * FROM import_jobs
WHERE status = 'processing'
  AND started_at < NOW() - INTERVAL 30 MINUTE;
```

A scheduled artisan command (`imports:check-stuck`) would:

1. Find stuck imports
2. Check actual progress against `total_rows`
3. Option A (safe): Set status to `failed` and log the error for manual investigation
4. Option B (aggressive): Re-dispatch the next unprocessed batch by comparing `processed_rows` against batch boundaries

**For this implementation:** We use Option A (fail-safe) because we cannot reliably determine which batches completed without a separate batch tracking table. A production system would add an `import_job_batches` table to track individual batch state.

**Prevention:** Each batch job uses `lockForUpdate()` within a transaction. If the job crashes before commit, the transaction rolls back and `processed_rows` is unchanged. The batch simply never reports success.

### Q: If the user cancels while a batch is actively running, how do you ensure no new batches start and the running batch is stopped gracefully?

The cancellation flow works in layers:

1. **API handler** sets `status = cancelled` and `cancelled_at = now()` on the `import_jobs` record.

2. **Future batches haven't started yet** — `Bus::batch()` dispatches all jobs upfront, but each job checks the cancellation flag at the start:

   ```php
   public function handle(): void
   {
       if ($this->batch() && $this->batch()->cancelled()) {
           return;
       }

       $importJob = ImportJob::findOrFail($this->importJobId);
       if ($importJob->isCancelled() || $importJob->isFailed()) {
           return;
       }
       // ... process ...
   }
   ```

3. **Currently running batch** — PHP is single-threaded per process, so we can't forcefully terminate the running batch. However, we implement **graceful checkpointing**: the batch checks the import status after each row:

   ```php
   // In a long-running batch, check periodically
   if ($processedInBatch % 10 === 0) {
       $importJob->refresh();
       if ($importJob->isCancelled()) {
           // Save progress so far and exit
           $this->saveProgress($processedInBatch, $failedInBatch, $batchErrors);
           return;
       }
   }
   ```

4. **Batch completion callback** — The `then()` callback checks the status. If cancelled, it respects the cancellation and sets `completed_at` without overriding the `cancelled` status.

**Caveat:** A batch that's in the middle of a database transaction for a single row cannot be interrupted mid-row. The cancellation takes effect between rows. For rows that each take <100ms to process, this is acceptable.

---

## 8. Observability & Alerting

### Metrics

| Metric                           | Source                                                                                | Purpose                            |
| -------------------------------- | ------------------------------------------------------------------------------------- | ---------------------------------- |
| `imports_started`                | `import_jobs` where `status` changed to `processing`                                  | Track import volume                |
| `imports_completed`              | `import_jobs` where `status = 'completed'`                                            | Track throughput                   |
| `imports_failed`                 | `import_jobs` where `status = 'failed'`                                               | Track failure rate                 |
| `imports_cancelled`              | `import_jobs` where `status = 'cancelled'`                                            | Track user-initiated cancellations |
| `avg_processing_time_per_import` | `AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at))` grouped by `completed_at` date | Track performance trends           |
| `avg_processing_time_per_row`    | `AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at) / total_rows)`                   | Track per-row cost                 |
| `batch_failure_rate`             | `SUM(failed_rows) / SUM(total_rows)` over a time window                               | Track data quality                 |

### Stuck Import Detection Query

```sql
SELECT
    id,
    account_id,
    user_id,
    filename,
    total_rows,
    processed_rows,
    started_at,
    TIMESTAMPDIFF(MINUTE, started_at, NOW()) AS stuck_minutes
FROM import_jobs
WHERE status = 'processing'
  AND started_at < NOW() - INTERVAL 30 MINUTE
ORDER BY started_at ASC;
```

This query uses the index on `(account_id, status)` and can be run as a monitoring check every 5 minutes.

### Alerting Rule

**Condition:** `SUM(failed_rows) / SUM(total_rows) > 0.20` across all imports in the last hour

```sql
SELECT
    SUM(processed_rows) AS total_processed,
    SUM(failed_rows) AS total_failed,
    SUM(total_rows) AS total_rows,
    ROUND(SUM(failed_rows) * 100.0 / NULLIF(SUM(total_rows), 0), 1) AS failure_rate_pct
FROM import_jobs
WHERE created_at > NOW() - INTERVAL 1 HOUR
  AND status IN ('completed', 'failed')
HAVING failure_rate_pct > 20;
```

**Suggested alert:** PagerDuty or Slack notification if the failure rate exceeds 20% for two consecutive 5-minute checks (to avoid flapping).

**Rationale for 20%:** A 20% row failure rate suggests systemic issues (bad CSV template, broken import logic, upstream data source change) rather than isolated bad rows. Single-row failures are expected (duplicate emails, malformed data), but 1 in 5 rows failing warrants investigation.

---

## 9. Production Awareness

### At 10× Scale

| Concern                  | Mitigation                                                                                  |
| ------------------------ | ------------------------------------------------------------------------------------------- |
| File storage             | Move from local disk to S3 (change `config/filesystems.php`, zero code changes)             |
| Queue throughput         | Dedicate queue workers to the `imports` queue, use Laravel Horizon for monitoring           |
| Error JSON growth        | Migrate `import_jobs.errors` to a separate `import_job_errors` table with FK and pagination |
| DB write contention      | Reduce batch size (increase parallelism), or use Redis counters with periodic DB flush      |
| Concurrent large imports | Implement per-account import concurrency limit (max 2 concurrent imports per account)       |

### Rollback Strategy

The entire import feature is isolated in:

- **New routes** — prefix is `api/import/*`, no existing routes are modified
- **New jobs** — `ProcessImportJob`, `ProcessImportBatchJob` (not used by any existing code)
- **New table** — `import_jobs` (no existing tables modified)

To roll back:

```
1. Remove or comment out import routes in routes/api.php
2. php artisan queue:clear (remove pending import jobs)
3. Drop import_jobs table (php artisan migrate:rollback)
4. Delete new files (controllers, jobs, services, resources, tests)
```

No existing Monica functionality is affected.

### Debugging a Stuck Import

1. **Check the import_job record:**
   ```sql
   SELECT * FROM import_jobs WHERE id = '<import_id>' \G
   ```
2. **Check queue status:**
   ```bash
   php artisan queue:status
   php artisan queue:failed  # Check for failed batch jobs
   ```
3. **Check job batches table:**
   ```sql
   SELECT * FROM job_batches WHERE name LIKE '%<import_id>%';
   ```
4. **Re-run if needed:** Create an artisan command that allows re-dispatching a failed import:
   ```bash
   php artisan import:retry <import_id>
   ```

---

## 10. Architecture Decision Records (ADR)

### ADR-1: Database (MySQL) vs Redis for Progress Tracking

**Decision:** Use the MySQL `import_jobs` table for progress tracking.

**Alternatives considered:**

- **Redis:** Faster writes (~1ms vs ~5ms for MySQL), but adds infrastructure dependency and risk of data loss on Redis restart.
- **Separate events stream (Laravel Pulse / Prometheus):** Better for aggregated metrics but doesn't serve per-import progress to users.

**Rationale:** The write volume is low — one update per batch (50 rows), not per row. For a 10,000-row file, that's 200 updates total. MySQL handles this trivially. Using MySQL keeps the stack simple, avoids cache invalidation problems, and provides ACID guarantees for the progress counters.

**Trade-off:** At extreme scale (millions of rows/hour, thousands of concurrent imports), the `lockForUpdate()` row lock could become a contention point. Mitigation: reduce batch size or switch to optimistic locking.

### ADR-2: Batch Size = 50

**Decision:** 50 rows per batch job.

**Alternatives considered:**

- **10:** Very granular progress (10% per update for small imports), but 500 batch jobs for a 5000-row file.
- **100:** Fewer batch jobs, but higher memory usage (~1MB per batch) and longer per-job execution.
- **500:** Risk of PHP timeout (30s limit) on complex rows.

**Rationale:** 50 rows × ~5KB memory per row = ~250KB per batch. Each batch creates contacts + contact info, averaging 3-5 database inserts per row = 150-250 inserts per batch. At ~5ms per insert, that's ~1 second per batch. Leaves plenty of headroom before the 30s queue timeout.

**Trade-off:** If row processing is much slower (e.g., photo downloads, external API calls), the batch size should be reduced. The per-import `batch_size` column makes this configurable without deployment.

### ADR-3: Single Queue vs Per-Import Queues

**Decision:** Single `imports` queue.

**Alternatives considered:**

- **Per-import queue:** `imports-{importJobId}` — provides strict FIFO ordering per import and prevents one import from blocking another. But requires dynamic queue configuration and complicates monitoring.
- **Per-account queue:** `imports-{accountId}` — prevents noisy-neighbor problem between tenants. Useful for SaaS but adds complexity.

**Rationale:** A single queue with dedicated workers is simpler to operate and monitor. With the database driver (development) or Redis (production), jobs are processed FIFO within the queue. Tests use the `sync` driver for deterministic execution. At 10× scale, we'd use Laravel Horizon with a dedicated queue named `imports` and multiple workers.

**Trade-off:** If one import has 50,000 batches and another has 1, the large import could delay the small one. Mitigation: Use Laravel's `->onQueue('imports:high')` for small imports and `->onQueue('imports:low')` for large ones.

### ADR-4: File Storage — Local Disk vs S3 vs Database

**Decision:** Local disk via Laravel's Storage facade (swap to S3 via config).

**Alternatives considered:**

- **Database (BLOB column):** Easy backup but bloats the database, makes queries slower.
- **S3 directly:** Best for horizontal scaling but requires network I/O during processing and adds complexity for local development.

**Rationale:** The Storage facade abstracts the filesystem. Local disk is fast for single-server deployments (common for Monica self-hosted). For the SaaS version, changing `FILESYSTEM_DISK=s3` is a single env var change, zero code changes. The file must be retained for error CSV reconstruction.

**Trade-off:** Local storage doesn't scale horizontally. Multiple app servers behind a load balancer would each need access to the same file storage (NFS or move to S3).

---

## 11. Tests

### Running Tests

```bash
php artisan test --filter=Import
# Or
vendor/bin/phpunit tests/Feature/Import/ImportTest.php
```

### Test Configuration

- **Database:** In-memory SQLite (`DB_TEST_DATABASE=:memory:`)
- **Queue:** Sync driver (`QUEUE_CONNECTION=sync`) — jobs execute immediately during the HTTP request
- **Storage:** Fake local driver (`Storage::fake('local')`) — files kept in memory
- **Schema:** `DatabaseMigrations` trait rebuilds all tables before each test

### Test Coverage (11 tests, 71 assertions)

| Test                                       | What It Verifies                                                                                                                                  |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| `it_creates_an_import_job_on_upload`       | Upload endpoint accepts CSV, validates vault_id, returns 201 with proper JSON structure, creates `import_jobs` record, stores file                |
| `it_processes_import_and_updates_progress` | Full pipeline: CSV parsing, batch dispatching, contact creation, progress counters updated, status transitions `pending → processing → completed` |
| `it_handles_row_errors_and_continues`      | Invalid rows (missing name, bad email) are skipped with errors recorded, valid rows still processed, import completes as `completed` not `failed` |
| `it_returns_import_status_via_api`         | `GET /api/import/:id` returns detailed status including `progress_pct`, `processed_rows`, `failed_rows`, `started_at`, `completed_at`             |
| `it_lists_imports_paginated`               | `GET /api/import` returns paginated results with `meta` structure (`current_page`, `per_page`, `total`, `last_page`)                              |
| `it_cancels_a_running_import`              | Cancel endpoint changes status to `cancelled`, sets `cancelled_at`, rejects cancellation on non-processing imports                                |
| `it_returns_errors_paginated`              | Error list endpoint returns paginated errors with correct `per_page` and `last_page`                                                              |
| `it_downloads_error_csv`                   | Error CSV contains original row data plus `error` column, correct headers, attachment disposition                                                 |
| `it_rejects_unauthorized_access`           | Users cannot view other users' imports (404 response)                                                                                             |
| `it_validates_required_fields`             | Upload without file or vault_id returns 422 validation error                                                                                      |
| `batch_job_checks_cancellation_flag`       | Batch job exits early when import status is `cancelled`, no contacts created                                                                      |

### Caching & Invalidation

The current implementation does not cache `import_jobs` data because:

1. The progress endpoint reads a single row by primary key — MySQL handles this in <1ms
2. Caching progress would introduce staleness (user sees 45% instead of 52%)
3. Cache invalidation would be required after every batch job update

If caching were needed (e.g., the list endpoint at very high traffic), the cache key would be `import_list:user:{userId}:page:{page}` and would be invalidated when a new import is created or any import's status changes.

---

## 12. Files Changed/Added

### New Files

| File                                                                 | Purpose                                                                             |
| -------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| `database/migrations/2026_06_05_000001_create_import_jobs_table.php` | Import jobs schema                                                                  |
| `app/Models/ImportJob.php`                                           | Import job model with scopes, status helpers, progress calculation                  |
| `database/factories/ImportJobFactory.php`                            | Factory for test data generation                                                    |
| `app/Http/Resources/ImportJobResource.php`                           | API resource with progress_pct and ETA                                              |
| `app/Domains/Contact/Import/Services/CsvParser.php`                  | CSV parsing via Storage facade                                                      |
| `app/Domains/Contact/Import/Services/ImportContactFromRow.php`       | Single-row import using CreateContact + CreateContactInformation                    |
| `app/Domains/Contact/Import/Jobs/ProcessImportJob.php`               | Main orchestrator — parses CSV, counts rows, dispatches batches, handles completion |
| `app/Domains/Contact/Import/Jobs/ProcessImportBatchJob.php`          | Batch processor — 50 rows, per-row error isolation, atomic progress update          |
| `app/Domains/Contact/Import/Api/Controllers/ImportController.php`    | All 6 API endpoints                                                                 |
| `tests/TestCase.php`                                                 | Test base class                                                                     |
| `tests/Feature/Import/ImportTest.php`                                | 11 feature tests                                                                    |

### Modified Files

| File             | Change                                                                             |
| ---------------- | ---------------------------------------------------------------------------------- |
| `routes/api.php` | Added `import` resource routes + cancel/errors/errors.csv + `get-token` route      |
| `.env`           | Changed `QUEUE_CONNECTION=sync` → `QUEUE_CONNECTION=database` for async processing |
| `phpunit.xml`    | Added `DB_TEST_DATABASE=:memory:` for in-memory SQLite testing                     |
