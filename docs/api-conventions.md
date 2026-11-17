# API conventions

Base URL: `http://localhost:8000` (configurable). All endpoints live under `/api`, accept and return JSON, and use **session cookie**
authentication (Laravel Sanctum SPA mode).

## Requests

- Send `Accept: application/json`. The API never redirects to HTML pages.
- Browser requests must come from a stateful origin (`SANCTUM_STATEFUL_DOMAINS`, default `localhost:3000`) with
  `credentials: "include"`.
- State-changing requests (`POST/PUT/PATCH/DELETE`) need `X-XSRF-TOKEN` = URL-decoded value of the `XSRF-TOKEN` cookie, obtained from
  `GET /sanctum/csrf-cookie`.
- Query parameters are validated by Form Requests; unknown filter values return **422** (they are never silently ignored).
- Search parameters are matched case-insensitively (`ILIKE`); `%` and `_` are escaped and treated literally.

## Responses

**Single resource**

```json
{ "data": { "id": 1, "name": "Elena Marsh", "email": "admin@lexora.test", "role": "admin" } }
```

**Collection (always paginated)**

```json
{
  "data": [ { "...": "..." } ],
  "meta":  { "current_page": 1, "last_page": 2, "per_page": 15, "total": 18, "from": 1, "to": 15 },
  "links": { "first": "…?page=1", "last": "…?page=2", "prev": null, "next": "…?page=2" }
}
```

`per_page` defaults to 15, maximum 100. Implemented by `App\Http\Resources\PaginatedCollection`.

**Errors** — always `{ "message": string, "errors"?: { field: string[] } }`

| Status | When | Example message |
| --- | --- | --- |
| 400 | Session login attempted outside the SPA (no stateful origin) | `Session authentication is only available to the Lexora web application.` |
| 401 | No/expired session | `Unauthenticated.` |
| 403 | Authenticated but not allowed (policy / admin middleware) | `This action is unauthorized.` |
| 404 | Unknown endpoint or record (no model names leaked); also a task/note id that belongs to another matter | `The requested resource was not found.` |
| 409 | Conflict: stale `lock_version`, archived (read-only) matter, or deleting a client that has matters | `This matter is archived and read-only. Restore it to make changes.` |
| 419 | Missing/invalid CSRF token | `CSRF token mismatch.` |
| 422 | Validation failed / wrong credentials | `These credentials do not match our records.` + `errors.email` |
| 429 | Rate limit; includes `Retry-After` header | `Too many login attempts. Please try again in 58 seconds.` |
| 500 | Unexpected error (no stack trace when `APP_DEBUG=false`) | `Server Error` |

**Formats**: timestamps are ISO-8601 with offset in UTC (`2026-11-19T17:00:00+00:00`); enums are lower-case snake strings
(`in_progress`); IDs are integers. Internal fields (password hashes, remember tokens, storage paths) are never serialised.

**Calendar dates** (Phase 2): deadlines and opening dates are sent and edited as `YYYY-MM-DD` (`due_date`, `opened_date`),
interpreted in the firm's time zone (`LEXORA_TIMEZONE`). A deadline is stored as the end of that day; responses carry both the
date (`due_date`) and the UTC instant (`due_at`). Clients must display the date field, never re-derive it from the instant.

**Optimistic concurrency** (Phase 2): matters, tasks and notes expose `lock_version`. Edits may send the version they were based
on; if it is no longer current the API answers **409** and changes nothing. Omitting it means "last write wins".

**Server-managed fields are rejected, not ignored** (422 `prohibited`): e.g. `matter_number`, `closed_at`, `archived_at`,
`created_by`, `author_id`, `matter_id`, `completed_at`.

## Endpoints (Phase 1)

| Method | Path | Auth | Description |
| --- | --- | --- | --- |
| GET | `/sanctum/csrf-cookie` | – | Sets the `XSRF-TOKEN` cookie (204) |
| POST | `/api/auth/login` | – (throttled) | Body `{ email, password, remember? }` → 200 `{ data: User }` |
| GET | `/api/auth/me` | admin | Current administrator |
| POST | `/api/auth/logout` | admin | Ends the session → 204 |
| GET | `/api/dashboard/summary` | admin | Aggregated dashboard (see below) |
| GET | `/api/matters` | admin | Paginated matters |
| GET | `/api/clients` | admin | Paginated clients |
| GET | `/up` | – | Health check |

## Endpoints (Phase 2)

All require an administrator session (`auth:sanctum` + `admin` + `throttle:api`) and, for writes, the CSRF header.

| Method | Path | Body / notes | Success |
| --- | --- | --- | --- |
| POST | `/api/clients` | `name*`, `client_type*`, `email`, `phone`, `address`, `reference_number` (unique) | 201 `{ data: Client }` |
| GET | `/api/clients/{client}` | Client with `matters_count`, `active_/closed_/archived_matters_count` | 200 |
| PATCH | `/api/clients/{client}` | any client field | 200 |
| DELETE | `/api/clients/{client}` | only when the client has no matters (otherwise 409) | 204 |
| POST | `/api/matters` | `title*`, `client_id*`, `priority`, `status` (open/in_progress/on_hold), `matter_type`, `description`, `opened_date`, `due_date` | 201 `{ data: Matter }` (number generated) |
| GET | `/api/matters/{matter}` | Matter + client reference + `tasks_count`, `open_/overdue_/completed_tasks_count`, `notes_count` | 200 |
| PATCH | `/api/matters/{matter}` | `title`, `description`, `client_id`, `matter_type`, `priority`, `opened_date`, `due_date`, `lock_version` (status → 422) | 200 |
| POST | `/api/matters/{matter}/status` | `status*` (not `archived`), `lock_version`; invalid transition → 422 `errors.status` | 200 |
| POST | `/api/matters/{matter}/archive` | `lock_version` | 200 |
| POST | `/api/matters/{matter}/restore` | `lock_version`; returns to the status held before archiving | 200 |
| GET | `/api/matters/{matter}/activity` | `page`, `per_page` (≤100); newest first | 200 paginated `ActivityEvent` |
| GET | `/api/matters/{matter}/tasks` | `status` = `todo`/`in_progress`/`completed`/`open`; open first, by deadline | 200 paginated `Task` |
| POST | `/api/matters/{matter}/tasks` | `title*`, `description`, `status`, `priority`, `due_date` | 201 `{ data: Task }` |
| PATCH | `/api/matters/{matter}/tasks/{task}` | any task field, `lock_version`; status drives `completed_at` | 200 |
| DELETE | `/api/matters/{matter}/tasks/{task}` | recorded as `task.deleted` | 204 |
| GET | `/api/matters/{matter}/notes` | newest first | 200 paginated `MatterNote` |
| POST | `/api/matters/{matter}/notes` | `body*` (plain text, ≤ 10 000) | 201 |
| PATCH | `/api/matters/{matter}/notes/{note}` | `body*`, `lock_version` | 200 |
| DELETE | `/api/matters/{matter}/notes/{note}` | text removed; `note.deleted` event kept | 204 |

Writes to an **archived** matter (details, status, tasks, notes) answer 409 until it is restored.

Why a separate status endpoint instead of `status` in PATCH: transitions have rules, side effects on lifecycle timestamps and
their own history event; a dedicated action makes the allowed moves explicit (`allowed_transitions` in every Matter payload)
and keeps "edit details" from silently changing the lifecycle.

Phase 2 objects (additions):

- **Matter**: `opened_date`, `due_date`, `archived_from_status`, `created_at`, `lock_version`, `allowed_transitions[]`, and on
  the detail endpoint the task/note counters above. `client` gains `reference_number` on the detail endpoint.
- **Task**: `id, matter_id, title, description, status, priority, is_open, is_overdue, due_at, due_date, completed_at,
  created_by {id, name}, created_at, updated_at, lock_version`.
- **MatterNote**: `id, matter_id, body, author {id, name}, is_edited, created_at, updated_at, lock_version`.
- **ActivityEvent**: `id, event_type, description, actor {type, label}, subject_type, subject_id, metadata, occurred_at`.
  Metadata only holds non-confidential values (codes, dates, titles, changed field names) — never note text or descriptions.

### `GET /api/matters`

| Param | Values |
| --- | --- |
| `search` | ≤ 100 chars; matches title, matter number, matter type, client name, client reference (Phase 2) |
| `status` | `open`, `in_progress`, `on_hold`, `closed`, `archived`, or `active` (open + in progress + on hold) |
| `priority` | `low`, `medium`, `high`, `urgent` |
| `client_id` | integer |
| `matter_type` | exact matter type (Phase 2) |
| `deadline` | `overdue`, `upcoming` (dashboard window), `none` (Phase 2) |
| `sort` | `updated_at` (default), `created_at` (Phase 2), `opened_at`, `due_at` (nulls last), `matter_number`, `title`, `priority` (by weight) |
| `direction` | `asc` / `desc` (sensible default per sort) |
| `page`, `per_page` | pagination |

Matter object: `id, matter_number, title, description, matter_type, status, priority, is_active, is_overdue, opened_at, due_at,
closed_at, archived_at, updated_at, client {id, name, client_type}, open_tasks_count`.

### `GET /api/clients`

| Param | Values |
| --- | --- |
| `search` | name, email, reference number, phone (Phase 2) |
| `type` | `individual`, `organization` |
| `sort` | `name` (default), `created_at`, `matters_count` |
| `direction`, `page`, `per_page` | as above |

Client object: `id, name, client_type, email, phone, address, reference_number, matters_count, active_matters_count, created_at`.

### `GET /api/dashboard/summary`

```json
{
  "data": {
    "stats": { "total_clients": 8, "active_matters": 7, "total_matters": 10, "upcoming_deadlines": 4,
               "overdue_matters": 1, "open_tasks": 11, "overdue_tasks": 1 },
    "status_breakdown": [ { "status": "open", "label": "Open", "count": 3 }, "…" ],
    "upcoming_window_days": 14,
    "recent_matters": [ "Matter…" ],
    "upcoming_deadlines": [ "Matter…" ],
    "generated_at": "2026-11-16T14:00:00+00:00"
  }
}
```

Definitions of each figure: [architecture.md § Dashboard definitions](architecture.md#dashboard-definitions).

## Endpoints (Phase 3 — documents)

All require an administrator session; writes need the CSRF header. Uploads are `multipart/form-data`.

| Method | Path | Body / notes | Success |
| --- | --- | --- | --- |
| GET | `/api/documents` | library: `search` (title, original file name, matter number/title, client name, category label — metadata only), `category`, `status` (`active` default, `archived`, `all`), `matter_id`, `client_id`, `uploaded_from`/`uploaded_to` (YYYY-MM-DD), `sort` (`updated_at`, `created_at`, `title`, `matter_number`, `category`), `direction`, `page`, `per_page` | 200 paginated `Document` |
| GET | `/api/matters/{matter}/documents` | same filters, scoped to the matter | 200 paginated |
| POST | `/api/matters/{matter}/documents` | `file*` (PDF ≤ `DOCUMENTS_MAX_UPLOAD_MB`), `category*`, `title` (defaults to the file name), `description` | 201 `{ data: Document }` with `current_version` v1 |
| GET | `/api/matters/{matter}/documents/{document}` | document + current version + matter/client | 200 |
| PATCH | `/api/matters/{matter}/documents/{document}` | `title`, `category`, `description`, `lock_version` (a `file` → 422: upload a version instead) | 200 |
| POST | `…/documents/{document}/archive` · `/restore` | `lock_version`; versions are never deleted | 200 |
| GET | `…/documents/{document}/versions` | newest first, `is_current` flag | 200 paginated `DocumentVersion` |
| POST | `…/documents/{document}/versions` | `file*`, `lock_version`; identical to the current version → 422 | 201 `{ data: DocumentVersion }` |
| GET | `…/versions/{version}` | metadata | 200 |
| GET | `…/versions/{version}/preview` | PDF bytes, `Content-Disposition: inline` (not recorded) | 200 `application/pdf` |
| GET | `…/versions/{version}/download` | PDF bytes, `attachment`; recorded as `document.downloaded` | 200 `application/pdf` |

Every id is resolved through its parent (matter → document → version): a document of another matter or a version of another
document is **404**. Writes to an archived matter or archived document are **409**. Upload validation errors are 422 on `file`
(not a PDF, damaged/truncated, encrypted, empty, too large, wrong extension, duplicate); a body above PHP's `post_max_size`
answers **413** JSON.

File responses carry `Content-Type: application/pdf`, `Content-Disposition` (RFC 6266/5987: ASCII fallback + UTF-8 name),
`X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, `Content-Security-Policy: default-src 'none'; sandbox`,
`X-Frame-Options: DENY`, `Cross-Origin-Resource-Policy: same-site`, `X-Document-Version`, `X-Document-Sha256`. Before streaming,
the file's SHA-256 is recomputed; a mismatch answers **409** and is logged. A missing file answers 404 without revealing paths.

- **Document**: `id, matter_id, title, description, category, category_label, status, origin, versions_count, current_version,
  uploaded_by {id, name}, matter {id, matter_number, title, status, client {id, name}}, archived_at, created_at, updated_at,
  lock_version`.
- **DocumentVersion**: `id, document_id, version_number, label ("v1"), kind, origin, original_filename, mime_type, file_size,
  sha256_checksum, uploaded_by {id, name}, is_current, created_at`. Storage disk and path are never serialised.

## Endpoints (Phase 4 — client document requests)

### Administrator (session + CSRF, like every endpoint above)

| Method | Path | Body / notes | Success |
| --- | --- | --- | --- |
| GET | `/api/document-requests` | `search` (request title, matter number/title, client name), `status` (a status or `open`, `awaiting_client`, `awaiting_review`), `overdue=1`, `matter_id`, `client_id`, `sort` (`updated_at`, `created_at`, `due_at`, `title`, `last_submitted_at`, `matter_number`), `direction`, `page`, `per_page`. Outstanding views exclude archived matters (same rule as the dashboard). | 200 paginated `DocumentRequest` |
| GET | `/api/matters/{matter}/document-requests` | same filters, scoped | 200 paginated |
| POST | `/api/matters/{matter}/document-requests` | `title*`, `instructions*`, `requested_category*`, `due_date` (YYYY-MM-DD, not in the past), `issue_link` (bool), `link_expires_in_days` (1–30, default 7) | 201 `{ data, link: IssuedLink \| null }` |
| GET | `…/document-requests/{documentRequest}` | request + latest submission + active link + approved version | 200 `{ data, links: SecureLink[] }` |
| PATCH | `…/document-requests/{documentRequest}` | `title`, `instructions`, `requested_category`, `due_date`, `lock_version*`; open requests only | 200 |
| POST | `…/{documentRequest}/links` | `expires_in_days`; 409 if a usable link exists | 201 `{ data, link }` |
| POST | `…/{documentRequest}/links/rotate` | `expires_in_days`; revokes the current link, issues a new one | 201 `{ data, link }` |
| POST | `…/{documentRequest}/links/{link}/revoke` | — | 200 `{ data, revoked_link }` |
| GET | `…/{documentRequest}/submissions` | newest first, with versions | 200 `{ data: Submission[] }` |
| POST | `…/{documentRequest}/start-review` | `submission_id*` (must be the latest), `lock_version*` | 200 |
| POST | `…/{documentRequest}/approve` | `submission_id*`, `lock_version*` | 200 |
| POST | `…/{documentRequest}/request-correction` | `submission_id*`, `lock_version*`, `reason*` (10–2000), `expires_in_days` | 200 `{ data, link }` |
| POST | `…/{documentRequest}/cancel` | `lock_version` | 200 |

Server-owned fields (`matter_id`, `client_id`, `status`, `document_id`, `document_version_id`, `approved_version_id`,
`approved_at`, `reviewed_by`, `created_by`, `token`, `token_hash`, `max_uses`, `successful_uses`, `consumed_at`, `expires_at`,
`purpose`, …) are **422 prohibited**. Invalid transitions are 422 on `status`; a stale `lock_version`, a newer submission, a closed
request, an archived matter or an existing usable link are **409**. A request of another matter, a link of another request → **404**.

- **DocumentRequest**: `id, matter_id, title, instructions, requested_category(+_label), status(+_label), is_open, is_overdue,
  due_at, due_date, document_id, submissions_count, last_submitted_at, review_started_at, approved_at, approved_version {id,
  version_number, label}, reviewed_by, cancelled_at, client {id, name}, matter {…}, document {…}, latest_submission, active_link,
  created_by, created_at, updated_at, lock_version, allowed_actions {edit, issue_link, rotate_link, revoke_link, start_review,
  approve, request_correction, cancel}`.
- **SecureLink**: `id, purpose, state (active|expired|revoked|used), expires_at, revoked_at, revocation_reason(+_label),
  consumed_at, first_opened_at, created_by, created_at` — never the token, its hash or the URL.
- **IssuedLink** (only in the response that created it): `id, url, expires_at, is_local_url, shown_once: true`.
- **Submission**: `id, submission_number, document_id, version (DocumentVersion), client_message, submitted_by, submitted_at,
  review_status(+_label), review_started_at, reviewed_at, reviewed_by, correction_reason`.
- Dashboard `stats` gained `requests_outstanding, requests_pending, requests_awaiting_review, requests_correction,
  requests_overdue, requests_approved`; matter detail gained `requests {open, pending, awaiting_review, correction_requested,
  approved, overdue}`.

### Public client endpoints (no account, no cookies)

Authorised only by the `X-Upload-Token` header (the token from the link's URL fragment). No session/CSRF middleware; rate
limited; `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`. A foreign `Origin` → 403.

| Method | Path | Body / notes | Success |
| --- | --- | --- | --- |
| GET | `/api/public/document-upload` | — | 200 `{ data: { firm_name, title, instructions, category, category_label, due_date, is_overdue, expires_at, max_file_mb, is_correction, correction {reason, requested_at} \| null } }` |
| POST | `/api/public/document-upload/submit` | multipart `file*` (same PDF rules as admin uploads), `message` (≤ 2000) | 201 `{ message, data: { submission_number, submitted_at, original_filename, file_size, is_correction } }` |

Errors: `{ message, code }` — 404 `link_invalid` (unknown, malformed, wrong purpose, revoked/replaced — identical bodies),
410 `link_expired` / `link_used` / `request_approved` / `request_cancelled` / `request_unavailable`, 429 `too_many_attempts`,
422 validation (`errors.file`, `errors.message`). The token is checked before any file is parsed.

## Endpoints (Phase 5 — electronic signing)

### Administrator (session + CSRF)

Request, link, document and version ids are resolved through their parents (scoped bindings): an id from another matter → 404.

| Method | Path | Body / notes | Success |
| --- | --- | --- | --- |
| GET | `/api/signing-requests` | `search` (document title, signer, matter number/title), `status` (a status or `active`), `signer_type`, `sort` (`updated_at`, `created_at`, `signed_at`, `matter_number`), `direction`, `page`, `per_page` | 200 paginated `SigningRequest` |
| GET | `/api/matters/{matter}/signing-requests` | same filters, scoped | 200 paginated |
| POST | `/api/matters/{matter}/signing-requests` | `document_id*`, `document_version_id*` (the current `upload` version), `signer_type*` (`client`\|`admin`); client only: `signer_name*`, `signer_email`, `message` (≤ 2000), `issue_link`, `link_expires_in_days` (1–30). Admin requests designate the acting administrator; `signers` → 422 (one signer per PDF) | 201 `{ data, link: IssuedLink \| null }` |
| GET | `…/signing-requests/{signingRequest}` | request + source/signed versions + active link | 200 `{ data, links: SecureLink[], evidence: SignatureRecord \| null }` |
| GET | `…/signing-requests/{signingRequest}/evidence` | | 200 `{ data: SignatureRecord }` (404 until signed) |
| POST | `…/{signingRequest}/links` | `expires_in_days`, `lock_version`; 409 if a usable link exists | 201 `{ data, link }` |
| POST | `…/{signingRequest}/links/rotate` | revokes the current link, issues a new one | 201 `{ data, link }` |
| POST | `…/{signingRequest}/links/{link}/revoke` | | 200 `{ data, revoked_link }` |
| POST | `…/{signingRequest}/mark-shared` | `lock_version`; client request in `ready` with a usable link → `sent` (`delivery_channel = manual`). No e-mail is sent or claimed | 200 |
| POST | `…/{signingRequest}/cancel` | `lock_version`; active requests only; revokes usable links | 200 |
| POST | `…/{signingRequest}/admin-sign` | signature payload (below) + `lock_version`; designated administrator only; `confirmed_name` prohibited (the session's name is used) | 201 `SigningRequest` |
| GET | `/api/matters/{matter}/documents/{document}/versions/{version}/signing-eligibility` | | 200 `{ data: { eligible, page_count, pages: [{page, width, height}], consent {version, text} } }`; 409/422 with the reason otherwise |
| POST | `/api/matters/{matter}/documents/{document}/sign` | signature payload + `document_version_id*`: creates the administrator request and signs it in one transaction | 201 `SigningRequest` |

**Signature payload** (admin and public): `method*` (`drawn`\|`typed`), `strokes` (drawn: ≤ 60 strokes of normalised `[x, y]`
points, ≤ 500 per stroke, ≤ 4,000 total), `typed_name` (typed: ≤ 80, must match the signer), `page*`, `x*`, `y*` (0–1, top-left of
the box on the displayed page), `width*` (fraction of the page width; converted to 100–320 pt), `consent*` (accepted),
`consent_version*` (must equal the current `SigningConsent::VERSION`). Bodies over 256 KB → 413.

Errors: 422 validation (incl. `confirmed_name`/`typed_name` not matching the signer, `placement`, `signature`), 422
`{ code: "pdf_unsupported" }` (PDF the engine cannot sign) / `{ code: "signing_failed" }`, 409 (stale `lock_version`, wrong state,
already signed, open request exists, newer version added, archived matter/document, open client review), 404 cross-matter ids.

- **SigningRequest**: `id, matter_id, document_id, signer_type(+_label), signer_name, signer_email, message, status(+_label),
  is_active, delivery_channel, delivery_status(+_label), sent_at, viewed_at, started_at, signed_at, cancelled_at,
  cancellation_reason(+cancellation_label), matter {id, matter_number, title, status}, client, document {id, title, status,
  current_version_id}, source_version, signed_version, active_link, created_by, created_at, updated_at, lock_version,
  allowed_actions {issue_link, rotate_link, revoke_link, mark_shared, cancel, admin_sign}`.
- **SignatureRecord**: signer snapshot, `confirmed_name`, `signature_method`, `page_number`, `placement`, source/signed version ids
  and SHA-256, `consent_version`, `consent_text`, `consent_accepted_at`, `signed_at`, `ip_address`, `user_agent`, `identity_basis`.
- Dashboard `stats` gained `signatures_pending, signatures_signed`; matter detail gained `signatures {total, active, sent, viewed,
  started, signed}`; documents gained `active_signing_request`.

### Public signing endpoints (no account, no cookies)

Authorised only by the `X-Signing-Token` header (from the `/client-sign#<token>` fragment); `purpose = document_signing` links
only. Same isolation as the upload endpoints (no session/CSRF middleware, Origin allow-list, `no-store`, `no-referrer`, `noindex`).
Limits: `public-signing` (120/min per IP), `public-signing-complete` (10/min per IP); failed lookups count towards the IP lockout.
GET requests never change state.

| Method | Path | Body / notes | Success |
| --- | --- | --- | --- |
| GET | `/api/public/signing` | | 200 `{ data: { firm_name, document_title, version_label, file_name, signer_name, signer_email (masked), message, status, viewed, started, expires_at, consent {version, text} } }` |
| GET | `/api/public/signing/document` | the pinned source PDF only (SHA-256 re-verified) | 200 `application/pdf`, `Content-Security-Policy: default-src 'none'; sandbox` |
| POST | `/api/public/signing/view` | sent after the page rendered; idempotent | 200 `{ data: { status } }` |
| POST | `/api/public/signing/start` | only after viewed; idempotent | 200 `{ data: { status } }` |
| POST | `/api/public/signing/complete` | signature payload + `confirmed_name*` (must match the designated signer) | 201 `{ message, data: { status, signed_at, document_title } }` |

Errors: `{ message, code }` — 404 `link_invalid` (unknown, malformed, wrong purpose, manually revoked — identical), 410
`link_expired` / `already_signed` / `request_cancelled` / `request_unavailable` (archived), 409 (step order), 422 validation or
`pdf_unsupported`, 429 `too_many_attempts`.

## Conventions for new endpoints (Phase 2+)

- Route names `resource.action`, RESTful verbs, plural nouns (`/api/matters/{matter}/tasks`).
- Validation in a dedicated Form Request; authorisation in the request's `authorize()` or `Gate::authorize()` against a policy.
- Output only through API Resources; collections through a `PaginatedCollection` subclass.
- Multi-table writes in `DB::transaction()`, appending an `ActivityLogger` event inside the same transaction.
- Public (client-link) endpoints go under `/api/public/*` with their own rate limiter, token-hash lookup and minimal resources;
  they remove the stateful SPA middleware and read the token from a header, never from the URL (see Phase 4).
