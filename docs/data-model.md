# Data model

PostgreSQL 16. All timestamps are `timestamptz` stored in UTC. Enumerated columns are `varchar` + `CHECK` constraints generated
from the PHP enums in `backend/app/Enums`, so PHP and the database agree on valid values.

## Entity relationships (implemented)

```
users 1───* tasks.created_by           (nullable, ON DELETE SET NULL)
users 1───* matter_notes.author_id     (nullable, ON DELETE SET NULL)

clients 1───* matters                  (ON DELETE RESTRICT)
matters 1───* tasks                    (ON DELETE CASCADE)
matters 1───* matter_notes             (ON DELETE CASCADE)
matters 1───* activity_events          (nullable FK, ON DELETE RESTRICT)
matters 1───* documents 1───* document_versions                       (Phase 3)
matters 1───* document_requests 1───* document_request_submissions    (Phase 4; request.client = matter.client)
document_requests 1───* secure_access_links                           (Phase 4; ≤ 1 usable upload link at a time)
document_requests *───1 documents, submissions 1───1 document_versions (same matter / same document, composite FKs)
activity_events *───1 subject          (polymorphic: subject_type alias + subject_id)
```

**Cardinality assumptions**

- A matter has exactly **one primary client** (assignment scope). Related parties/co-clients would be a `matter_parties` pivot later.
- A client may have many matters; a client with any matter cannot be deleted.
- Tasks and notes always belong to one matter.
- Activity events usually belong to a matter; `matter_id` is nullable for future workspace-level events.
- Matters are **closed/archived, never hard-deleted** once they have history: the `activity_events` FK uses `RESTRICT`, and every matter
  gets a `matter.created` event. `tasks`/`matter_notes` cascade only so that a brand-new matter without history can be removed.

## Tables

### `users` (administrators)

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| name | varchar(255) | |
| email | varchar(255) **unique** | stored lower-cased (model mutator) → case-insensitive login |
| email_verified_at | timestamptz null | |
| password | varchar(255) | bcrypt hash (`hashed` cast) |
| role | varchar(20) default `admin` | `CHECK (role IN ('admin'))`, indexed |
| remember_token | varchar(100) null | "Keep me signed in" |
| created_at / updated_at | timestamptz | |

No client accounts exist. `sessions`, `cache`, `jobs`, `password_reset_tokens` and Sanctum's `personal_access_tokens` (unused — SPA
uses sessions; kept so a stray bearer token can never cause a 500) are Laravel framework tables.

### `clients`

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| name | varchar(200) | `CHECK (length(btrim(name)) > 0)`, indexed |
| client_type | varchar(20) | `individual` \| `organization` (CHECK), indexed |
| email | varchar(255) **null** (Phase 2) | indexed, **not unique** (deliberate — one contact address can serve several related clients); optional so a client can be recorded before an address is known |
| phone | varchar(50) null | |
| address | text null | |
| reference_number | varchar(50) null **unique** | firm's client reference, e.g. `CL-1001` |
| created_at / updated_at | timestamptz | |

### `matters`

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| matter_number | varchar(32) **unique** | e.g. `LEX-2026-0001` |
| client_id | FK → clients | `RESTRICT`, indexed |
| title | varchar(255) | non-blank CHECK |
| description | text null | |
| matter_type | varchar(100) null | free text (Contract Review, Employment Dispute…), indexed |
| status | varchar(20) default `open` | `open, in_progress, on_hold, closed, archived` |
| priority | varchar(20) default `medium` | `low, medium, high, urgent` |
| opened_at | timestamptz | |
| due_at | timestamptz null | the matter deadline |
| closed_at | timestamptz null | `CHECK (status <> 'closed' OR closed_at IS NOT NULL)`; Phase 2: `CHECK (status IN ('closed','archived') OR closed_at IS NULL)` |
| archived_at | timestamptz null | `CHECK (status <> 'archived' OR archived_at IS NOT NULL)`; Phase 2: `CHECK (status = 'archived' OR archived_at IS NULL)` |
| archived_from_status | varchar(20) null (Phase 2) | status held before archiving (restore target); only set on archived matters (CHECK) |
| lock_version | integer default 1 (Phase 2) | optimistic concurrency, incremented on every update (`CHECK >= 1`) |
| created_at / updated_at | timestamptz | `updated_at` indexed (recent matters); Phase 2 indexes `created_at`, `opened_at` |

Indexes: `(status, due_at)` serves the dashboard's active/upcoming/overdue queries; `priority`, `client_id`, `matter_type`.

**Date semantics (Phase 2).** `opened_at` / `due_at` stay `timestamptz` (UTC). The API exchanges them as calendar dates in the
firm's time zone (`LEXORA_TIMEZONE`): a deadline date is stored as the **end** of that day, an opening date as its start
(`App\Support\LegalDate`). A matter is overdue only after its whole deadline day has passed, and dates never shift by a day
between server and browser time zones.

#### Matter lifecycle (Phase 2 transition policy)

```
            ┌──────────── in_progress ◄────────┐
            │      ▲            │              │
            ▼      │            ▼              │
  open ◄──────► on_hold      closed ──reopen──►(open | in_progress)
    │  ▲           │            ▲
    │  └───────────┘            │
    └──────────── any active ───┘
  any non-archived ──archive──► archived ──restore──► (status held before archiving)
```

| From | Allowed (status endpoint) |
| --- | --- |
| open | in_progress, on_hold, closed |
| in_progress | open, on_hold, closed |
| on_hold | open, in_progress, closed |
| closed | open, in_progress (reopen — `closed_at` cleared) |
| archived | none — use **restore** |

Archive is allowed from every non-archived status (also from active states, e.g. a matter opened in error); it keeps
`closed_at`, sets `archived_at` and `archived_from_status`. Restore returns to `archived_from_status` (legacy rows: closed if
`closed_at` is set, otherwise open). Archived matters are **read-only** (details, status, tasks, notes → 409). Matters are never
hard-deleted. Rules live in `App\Enums\MatterStatus::allowedTransitions()` and `App\Services\MatterService`; the database CHECKs
above guarantee the timestamps stay consistent even if application code were bypassed.

### `matter_number_counters` (Phase 2)

| Column | Type | Notes |
| --- | --- | --- |
| year | smallint PK | `CHECK (year BETWEEN 2000 AND 2999)` |
| last_value | integer | last number issued for that year (`CHECK >= 1`) |
| updated_at | timestamptz | |

`MatterNumberGenerator` issues `LEX-{year}-{0000}` with one atomic `INSERT … ON CONFLICT (year) DO UPDATE SET last_value =
GREATEST(last_value, highest existing number) + 1 RETURNING last_value`, executed **inside the transaction that creates the
matter**. The row lock serialises concurrent creators on that single row (no table lock), a rolled-back creation also rolls back
the counter (no gaps from failures), and `GREATEST` keeps the counter ahead of numbers inserted without it (e.g. the seeder).
`matters.matter_number` stays `UNIQUE` as the final guarantee. Verified with 4 concurrent PHP processes drawing 100 numbers.

### `tasks`

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| matter_id | FK → matters | `CASCADE` |
| title | varchar(255) | non-blank CHECK |
| description | text null | |
| status | varchar(20) default `todo` | `todo, in_progress, completed` |
| priority | varchar(20) default `medium` | shared `Priority` enum |
| due_at | timestamptz null | |
| completed_at | timestamptz null | `CHECK ((status = 'completed') = (completed_at IS NOT NULL))` |
| created_by | FK → users null | `SET NULL`; set from the session, never from the request |
| lock_version | integer default 1 (Phase 2) | optimistic concurrency |
| created_at / updated_at | timestamptz | |

Indexes: `(matter_id, status)`, `(status, due_at)`.

Phase 2 behaviour: completing sets `completed_at` (now); reopening (to `todo`/`in_progress`) clears it — the CHECK above makes
any other combination impossible. Tasks may be deleted (operational items); the deletion is recorded as `task.deleted` with the
task's id and title.

### `matter_notes` (internal)

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| matter_id | FK → matters | `CASCADE` |
| author_id | FK → users null | `SET NULL` (note survives the author's account) |
| body | text | non-blank CHECK |
| created_at / updated_at | timestamptz | index `(matter_id, created_at)` |

Internal notes must **never** be exposed through unauthenticated client links (enforced by separate public controllers/resources in Phase 4).

Phase 2: `lock_version` added. Note bodies are plain text, never copied into activity metadata or logs. **Deletion policy:**
a deleted note is hard-deleted (its confidential text is not retained) and a `note.deleted` event records the fact, actor and time.

### `activity_events` (append-only history)

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| matter_id | FK → matters null | `RESTRICT` |
| actor_type | varchar(20) | `admin, client, system` (CHECK) |
| actor_id | bigint null | users.id for admins; the document request id for client submissions (Phase 4) (no FK — polymorphic by `actor_type`) |
| actor_label | varchar(200) null | display-name snapshot at event time |
| event_type | varchar(60) | e.g. `matter.created`, `task.completed`, later `signing.completed` — see `ActivityEventType` |
| subject_type / subject_id | varchar / bigint null | morph-map alias (`matter`, `task`, `matter_note`…) |
| description | varchar(500) | human-readable line for the timeline |
| metadata | jsonb null | structured details (e.g. `{from, to}` for status changes) |
| occurred_at | timestamptz | when it happened (may differ from insertion time) |
| created_at | timestamptz default now() | no `updated_at` |

Append-only is enforced twice: the Eloquent model throws on `updating`/`deleting`, and a PostgreSQL trigger
(`activity_events_no_update_delete`) raises on any `UPDATE`/`DELETE`. Indexes: `(matter_id, occurred_at)`, `event_type`, `occurred_at`.

Event types emitted from Phase 2 (always in the same transaction as the change): `matter.created`, `matter.updated`
(metadata `changes` with before/after for title, type, client, dates; description only as `changed: true`),
`matter.status_changed`, `matter.priority_changed`, `matter.archived`, `matter.restored`, `task.created`, `task.updated`,
`task.completed`, `task.reopened`, `task.deleted`, `note.added`, `note.updated`, `note.deleted`, and client events
`client.created`, `client.updated` (changed field names only), `client.deleted` with `matter_id = null`.

Phase 3 adds `document.uploaded`, `document.version_added` (metadata: document/version ids, version number, category, file
name, size, SHA-256 — never paths or content), `document.updated` (changed fields; description only as `changed: true`),
`document.archived`, `document.restored` and `document.downloaded` (audit of file access; inline previews are not recorded).
Phase 4 adds (always in the change's transaction): `document.requested`, `document_request.updated` (changed fields; the
instructions text only as `changed: true`), `document_request.cancelled`, `link.created`, `link.rotated`, `link.revoked` (by an
administrator, or once per archive with `links_revoked` count), `document.submitted` and `document.resubmitted` (**actor_type
`client`**, actor_id = the request id, actor_label = the client's name; metadata includes `source: secure_link`, request,
submission, document and version ids, SHA-256, file name/size, `has_client_message`), `document.review_started`,
`document.approved`, `document.correction_requested` (`reason_recorded: true` — the reason text itself is stored only on the
submission). Status transitions are in `{from, to}`. Tokens, token hashes, URLs, paths, client messages and correction reasons
are never part of any event. Phase 5 adds `signing.requested`, `signing.link_created`, `signing.link_rotated`,
`signing.link_revoked`, `signing.invitation_shared`, `signing.cancelled` (admin actor; metadata: signing request, document and
version ids, `{from, to}`, reason, link ids/counts), `signing.viewed` and `signing.started` (**actor_type `client`**, actor_id =
the signing request id, `source: secure_link`), `signing.completed` and `document.signed` (admin or client actor; source and signed
version ids/numbers, signed SHA-256, signer type, signature method). Signature strokes, typed names and consent text are never
copied into activity.

### `documents` (Phase 3)

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| uuid | uuid **unique** | storage directory name (server-generated) |
| matter_id | FK → matters | `RESTRICT` (documents are part of the legal record); index `(matter_id, status)` |
| title | varchar(255) | non-blank CHECK |
| description | text null | |
| category | varchar(30) | `contracts, agreements, correspondence, evidence, court_documents, identification, other` (CHECK), indexed |
| status | varchar(20) default `active` | lifecycle only: `active, archived` (CHECK); `CHECK ((status='archived') = (archived_at IS NOT NULL))` |
| origin | varchar(20) default `admin` | `admin, client` (CHECK) — server-assigned; `client` arrives with Phase 4 requests |
| current_version_id | bigint null | composite FK `(current_version_id, id) → document_versions (id, document_id)`: the current version always belongs to this document |
| latest_version_number | integer default 0 | counter incremented under the document row lock; `CHECK ((current_version_id IS NULL) = (latest_version_number = 0))` |
| uploaded_by | FK → users null | `SET NULL` |
| archived_at | timestamptz null | |
| lock_version | integer default 1 | optimistic concurrency for metadata edits |
| created_at / updated_at | timestamptz | both indexed (library sorts) |

Review status (Phase 4) and signing status (Phase 5) are deliberately **not** columns here: they belong to requests and signing
requests, which reference specific versions. A document never becomes "signed" because of how its PDF looks.

### `document_versions` (Phase 3, immutable)

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | `UNIQUE (id, document_id)` — target of the composite current-version FK |
| uuid | uuid **unique** | stored file name |
| document_id | FK → documents | `RESTRICT` |
| version_number | integer | `CHECK >= 1`, **`UNIQUE (document_id, version_number)`** |
| kind | varchar(20) default `upload` | `upload, signed` (CHECK) — Phase 5 appends signed outputs as new `signed` versions (only `upload` versions can be signed) |
| origin | varchar(20) | `admin, client` (CHECK) |
| original_filename | varchar(255) | sanitised client name (metadata only), non-blank, indexed for search |
| storage_disk | varchar(50) | `documents` |
| storage_path | varchar(500) **unique** | `CHECK (storage_path ~ '^[0-9]+/[0-9a-f-]{36}/[0-9a-f-]{36}\.pdf$')` — only the server-generated layout, no traversal |
| mime_type | varchar(100) | `CHECK (mime_type = 'application/pdf')` |
| file_size | bigint | `CHECK > 0` |
| sha256_checksum | char(64) | `CHECK (~ '^[0-9a-f]{64}$')`, indexed (duplicate detection) |
| uploaded_by / uploaded_by_name | bigint null / varchar(200) | uploader snapshot **without FK** (an `ON DELETE SET NULL` would be an UPDATE, which immutability forbids) |
| created_at | timestamptz | no `updated_at` |

Immutability: trigger `document_versions_no_update_delete` rejects every `UPDATE`/`DELETE`; the model throws too. Versions are
only ever added. Phase 5 `signing_requests.document_version_id` can therefore reference the exact bytes that were signed, and the
signed result becomes a further version (`kind = signed`) without overwriting the source.
### `document_requests` (Phase 4)

| Column | Type | Notes |
| --- | --- | --- |
| `id`, `uuid` | bigint / uuid | `uuid` unique |
| `matter_id` | FK → matters (RESTRICT) | |
| `client_id` | FK → clients (RESTRICT) | always the matter's client at creation (trigger `document_requests_client_guard`); never client-supplied |
| `title`, `instructions` | varchar(255) / text | CHECK not blank; instructions are shown to the client |
| `requested_category` | varchar(30) | CHECK ∈ document categories |
| `status` | varchar(30) | CHECK ∈ `pending, submitted, under_review, correction_requested, approved, cancelled` |
| `due_at` | timestamptz null | end of the due day in the firm's time zone (`LegalDate`) |
| `document_id` | bigint null | composite FK `(document_id, matter_id)` → `documents (id, matter_id)`: the document is in the same matter |
| `submissions_count`, `last_submitted_at` | int / timestamptz | numbering counter, incremented under the request row lock |
| `created_by`, `review_started_by`, `reviewed_by`, `cancelled_by` | FK → users null (SET NULL) | |
| `review_started_at`, `approved_at`, `cancelled_at` | timestamptz null | |
| `approved_version_id` | bigint null | composite FK `(approved_version_id, document_id)` → `document_versions (id, document_id)` |
| `lock_version` | int ≥ 1 | optimistic concurrency (409 on stale review decisions/edits) |

CHECKs: `(status='approved') = (approved_at AND approved_version_id NOT NULL)`, `(status='cancelled') = (cancelled_at NOT NULL)`,
every state except `pending`/`cancelled` has a document, `(document_id IS NULL) = (submissions_count = 0)`.
Indexes: `(matter_id, status)`, `status`, `due_at`, `client_id`, `updated_at`; unique `(id, matter_id)` (FK target).
Trigger `matters_client_change_guard` refuses changing a matter's client while it has open requests.

### `secure_access_links` (Phase 4)

| Column | Type | Notes |
| --- | --- | --- |
| `id`, `uuid` | bigint / uuid | |
| `purpose` | varchar(30) | CHECK ∈ `document_upload, document_signing`; CHECK `secure_access_links_target` (Phase 5): an upload link targets only a document request, a signing link only a signing request |
| `matter_id` | FK → matters | composite FKs `(document_request_id, matter_id)` → `document_requests (id, matter_id)` and `(signing_request_id, matter_id)` → `signing_requests (id, matter_id)` |
| `document_request_id` | bigint null | |
| `signing_request_id` | bigint null | Phase 5; indexed |
| `token_hash` | char(64) **unique** | SHA-256 hex of a 256-bit random token; CHECK `^[0-9a-f]{64}$`; the token is never stored |
| `expires_at` | timestamptz | CHECK `expires_at > created_at` |
| `revoked_at`, `revocation_reason`, `revoked_by` | | reason ∈ `revoked, replaced, request_approved, request_cancelled, matter_archived`; CHECK both or neither |
| `consumed_at`, `max_uses` (1), `successful_uses` | | CHECK `successful_uses ≤ max_uses`, `(consumed_at NOT NULL) = (successful_uses ≥ max_uses)` |
| `first_opened_at` | timestamptz null | first time the client opened the page (opening never consumes) |
| `created_by` | FK → users null | |

Partial unique index `secure_access_links_one_usable_upload_link (document_request_id) WHERE purpose='document_upload' AND
revoked_at IS NULL AND consumed_at IS NULL`: at most one link per request can work. Phase 5 adds the same guarantee for signing
links (`secure_access_links_one_usable_signing_link` on `signing_request_id`). A signing link is consumed only by a completed
signature (opening and starting never consume it).

### `document_request_submissions` (Phase 4)

| Column | Type | Notes |
| --- | --- | --- |
| `document_request_id` | FK → document_requests | `submission_number` unique per request |
| `document_id`, `document_version_id` | bigint | composite FK `(document_version_id, document_id)` → `document_versions`; `document_version_id` unique |
| `secure_access_link_id` | FK → secure_access_links **unique** | a link can never produce a second submission |
| `client_message` | text null | confidential; never copied into activity metadata |
| `submitted_by_label`, `submitted_at` | | client name snapshot (clients have no account) |
| `review_status` | varchar(30) | `pending, in_review, approved, correction_requested, cancelled` |
| `review_started_at/_by`, `reviewed_at/_by`, `reviewed_by_name` | | |
| `correction_reason` | text null | CHECK required exactly when `correction_requested` |

Trigger `document_request_submissions_guard`: no DELETE; submitted facts never change; final decisions (`approved`,
`correction_requested`, `cancelled`) cannot be changed.

Additive change to an existing table: `documents` gained the UNIQUE constraint `(id, matter_id)` (composite FK target).

### `signing_requests` (Phase 5)

One designated signer for one immutable source version of one document — **one signer per PDF**.

| Column | Type | Notes |
| --- | --- | --- |
| `id`, `uuid` | bigint / uuid unique | the uuid's first 12 hex characters are the caption reference `LX-…` |
| `matter_id` | FK → matters (RESTRICT) | |
| `document_id` | bigint | composite FK `(document_id, matter_id)` → `documents (id, matter_id)` |
| `document_version_id` | bigint | the **source** PDF; composite FK `(document_version_id, document_id)` → `document_versions` |
| `signer_type` | varchar(20) | `admin, client` (CHECK) |
| `signer_user_id` | FK → users null (RESTRICT) | `CHECK ((signer_type='admin') = (signer_user_id IS NOT NULL))` |
| `client_id` | FK → clients null (RESTRICT) | `CHECK ((signer_type='client') = (client_id IS NOT NULL))`; must be the matter's client (trigger) |
| `signer_name`, `signer_email` | varchar(200) / varchar(255) null | snapshot; name not blank |
| `message` | text null | shown to the signer |
| `status` | varchar(20) default `ready` | `ready, sent, viewed, started, signed, cancelled` (CHECK) |
| `delivery_channel` | varchar(20) null | `manual` (Phase 5), `email` (Phase 6) |
| `delivery_status` | varchar(30) default `not_shared` | `not_shared, shared_manually, email_accepted` (Phase 6); CHECK consistent with `sent_at`; CHECK `signing_requests_delivery_channel_status` (Phase 6): `shared_manually` ⇔ channel `manual`, `email_accepted` ⇔ channel `email` |
| `sent_at`, `viewed_at`, `started_at`, `signed_at`, `cancelled_at` | timestamptz null | server time; CHECK each status has its timestamp, and sent ≤ viewed ≤ started ≤ signed |
| `cancellation_reason`, `cancelled_by` | varchar(30) null / FK users (SET NULL) | `manual, matter_archived, document_archived, source_superseded`; `(status='cancelled') = (cancelled_at AND reason NOT NULL)` |
| `signed_document_version_id` | bigint unique null | composite FK → `document_versions (id, document_id)`; `(status='signed') = (signed_at AND signed version NOT NULL)`; differs from the source |
| `created_by` | FK → users null (SET NULL) | |
| `lock_version` | int ≥ 1 | optimistic concurrency |

Indexes: partial unique `signing_requests_one_active_per_document (document_id) WHERE status IN ('ready','sent','viewed','started')`,
`(matter_id, status)`, `status`, `document_id`, `created_at`, `updated_at`, unique `(id, matter_id)` (FK target of links).
Triggers: `signing_requests_insert_guard` (source version must be `kind = upload`; client signer = matter client) and
`signing_requests_update_guard` (matter/document/source/signer never change; `signed`/`cancelled` are final; no DELETE).

### `signature_records` (Phase 5, immutable)

| Column | Type | Notes |
| --- | --- | --- |
| `signing_request_id` | FK → signing_requests **unique** (RESTRICT) | one record per request |
| `document_id` | bigint | |
| `signer_type`, `signer_user_id`, `signer_name`, `signer_email` | | designated signer snapshot (no FK on the user: immutable row) |
| `confirmed_name` | varchar(200) | the name the signer confirmed |
| `signature_method` | varchar(20) | `drawn, typed` (CHECK) |
| `page_number`, `placement` | int ≥ 1 / jsonb | normalised `x, y, width`, page size in points and `rect_pt` (the exact rectangle in PDF points) |
| `source_document_version_id`, `source_sha256` | bigint / char(64) | composite FK → `document_versions (id, document_id)` |
| `signed_document_version_id`, `signed_sha256` | bigint unique / char(64) | composite FK; CHECK hex-64 and source ≠ signed |
| `consent_version`, `consent_text`, `consent_accepted_at` | varchar(40) / text / timestamptz | the exact statement accepted (`SigningConsent`) |
| `signed_at` | timestamptz | server time (also printed in UTC in the PDF caption) |
| `ip_address`, `user_agent` | varchar(45) / varchar(255) null | at completion |
| `created_at` | timestamptz | no `updated_at` |

Trigger `signature_records_no_update_delete` rejects every `UPDATE`/`DELETE`. Signature strokes and typed text are not stored as
data (they exist only as drawing operations inside the signed PDF).

### `email_deliveries` (Phase 6)

One row per e-mail attempt. It never stores the link, the token or the message body.

| Column | Type | Notes |
| --- | --- | --- |
| `id`, `uuid` | bigint / uuid unique | the uuid is sent as header `X-Lexora-Delivery` |
| `matter_id`, `client_id` | FK → matters / clients (RESTRICT) null | null only for `test` |
| `document_request_id` | bigint null | composite FK `(document_request_id, matter_id)` → `document_requests (id, matter_id)` |
| `signing_request_id` | bigint null | composite FK `(signing_request_id, matter_id)` → `signing_requests (id, matter_id)` |
| `secure_access_link_id` | FK → secure_access_links **unique** null | the link this e-mail carried; set only when accepted |
| `communication_type` | varchar(40) | `document_request, document_correction, document_request_reminder, signing_invitation, signing_reminder, test`; CHECK target matches the type |
| `initiated_by`, `reminder_slot` | varchar(20) / varchar(80) null | `manual, automatic`; CHECK `automatic` ⇔ slot present, and automatic only for the reminder types |
| `recipient_email`, `recipient_name`, `subject` | | snapshot; CHECK recipient contains `@` and no CR/LF |
| `channel` | varchar(20) | `email` |
| `status` | varchar(20) | `pending, sending, accepted, failed, cancelled, skipped` |
| `attempt_count`, `last_attempt_at` | smallint / timestamptz | CHECK ≥ 1 unless `pending`, `skipped` or `cancelled` |
| `accepted_at`, `failed_at`, `failure_category` | timestamptz / timestamptz / varchar(40) | CHECK `accepted` ⇔ `accepted_at`; `failed` ⇔ `failed_at` and a category (skipped rows carry a category too); category ∈ `connection, timeout, authentication, recipient_rejected, sender_rejected, configuration, invalid_recipient, interrupted, unknown, ineligible, reminder_limit` |
| `provider_message_id` | varchar(255) null | ID returned by the SMTP server on acceptance |
| `idempotency_key` | varchar(160) **unique** | `manual:<type>:<id>:<uuid>` or `reminder:<type>:<id>:<slot>` |
| `created_by` | FK → users null (SET NULL) | |
| `created_at`, `updated_at` | timestamptz | |

Indexes: partial unique `email_deliveries_one_sending_per_document_request` / `…_per_signing_request` (`WHERE status =
'sending'`), `(document_request_id, created_at)`, `(signing_request_id, created_at)`, `(matter_id, created_at)`,
`(status, last_attempt_at)`. Trigger `email_deliveries_guard`: no DELETE; target and recipient never change; `accepted`,
`failed`, `cancelled` and `skipped` are final.

### `notifications` (Phase 6)

Laravel's notifications table: uuid `id`, `type`, `notifiable_type`/`notifiable_id` (morph to `users`), `data` jsonb (`kind`,
`title`, `body`, internal `href`, matter number/title and related ids — never links, tokens or document content), `read_at`,
`created_at`/`updated_at` as `timestamptz(6)` (exact ordering). Indexes `(notifiable_type, notifiable_id, read_at)` and
`created_at`. Kinds: `document_submitted, correction_submitted, document_signed, request_overdue, signing_link_expired,
email_failed`.

### Additive columns (Phase 6)

- `document_requests.overdue_notified_at` (timestamptz null): the overdue notification is sent once.
- `secure_access_links.expiry_notified_at` (timestamptz null): the expired-signing-link notification is sent once.
- Activity event types `email.accepted` and `email.failed` (metadata: delivery id, type, `initiated_by`, slot, request ids,
  recipient snapshot, outcome, link id — never the link itself).

## Seed data

`DemoWorkspaceSeeder` (development only, idempotent — keyed on `reference_number`, `matter_number`, `(matter_id, title)`,
`(matter_id, body)`): 1 admin (from `DEMO_ADMIN_*`), 8 clients, 10 matters (3 open, 3 in progress, 1 on hold, 2 closed, 1 archived;
4 deadlines within 14 days, 1 overdue), 15 tasks (11 open, 1 overdue), 6 notes and 42 activity events. Dates are relative to the
seeding time. Emails use reserved `example.*` domains and `555-01xx` phone numbers.

## Design notes

The planned `notifications` table and e-mail delivery log were implemented in Phase 6 as `notifications` and
`email_deliveries` (above). The signing history planned as a separate `signature_events` table was implemented instead as progress timestamps on
`signing_requests`, the immutable `signature_records` row and typed `activity_events` (Phase 5).
