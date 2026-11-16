# Phase 3 Implementation Report — Lexora Workspace (Amanet)

**Phase:** 3 of 7 — Secure Document Management, PDF Library, Version Control, Preview, Download, Search & Audit History
**Report date:** 16 November 2026
**Branch:** `feature/phase-3-document-management` (baseline: Phase 2 merge `840f8e0` on `main`)
**Environment:** Windows 11 Pro 10.0.26300, Laragon, PHP 8.3.35, PostgreSQL 16.16, Node.js 22.23.5 (the target machine)
**Status:** ✅ Phase 3 complete. Every acceptance criterion in §16 was executed and verified on the target machine; the full
regression (`verify-windows.bat`) passed **11/11 steps** (`verification-logs/verify-20261116-190747.log`).

Only implemented and executed work is described. Phase 1 and Phase 2 reports are unchanged.

---

## 1. Executive summary

**Completed.** Administrators can upload PDFs to a matter (from the matter's Documents tab or the new `/documents` library). Files
are stored privately with a SHA-256 fingerprint. Users can preview them in the app (PDF.js: pages, navigation, zoom) and
download them. They can upload new versions (v1, v2, v3…, never overwritten), preview or download **any** version, edit
document metadata, and archive/restore documents. They can search and filter the library on the server, by title, file name,
matter, client, category, status and upload date, with sorting and pagination kept in the URL. Uploads, new versions,
metadata changes, archive/restore and downloads appear in the matter's append-only activity timeline.

**Integrity and security.** Version rows are immutable at the database level. A composite foreign key guarantees the current
version belongs to its document. Version numbers are concurrency-safe: 4 processes uploaded 20 versions at once with no
duplicates or gaps. Paths are server-generated and constrained by a database CHECK. Every read re-verifies the file's SHA-256.
A failed upload leaves neither metadata nor files behind. Files are never web-served, and every endpoint requires an
administrator session plus matter-scoped ownership.

**Partially completed / limitations.** Validation is structural, not a malware scanner. Search covers metadata, not the text
inside PDFs. The viewer does not load PDF.js's optional WebAssembly image decoders (rare JPEG 2000/JBIG2 images may not render
in the preview; downloads are unaffected). Encrypted PDFs are rejected by design. See §10 and §16.

**Not in Phase 3 (by design):** client request links, client uploads, review/correction workflows, signing (Phases 4–5). The schema
is prepared for them (§15).

---

## 2. Original requirements mapping

| Amanet requirement | Phase 3 result |
| --- | --- |
| Document library — *upload* PDFs | ✅ Upload to a matter from the Documents tab or the library; byte-level validation; private storage |
| — *preview* | ✅ In-app PDF.js viewer: all pages, page navigation, page x of y, zoom, fit width, download; current and historical versions |
| — *download* | ✅ Authorised streaming of the exact requested version, safe headers, SHA-256 integrity check |
| — *organize* | ✅ Title, description, 7 categories, active/archived status, per-matter and workspace-wide lists |
| — *including previous versions* | ✅ Immutable version history; every version previewable and downloadable |
| Search & filters — *documents* | ✅ Server-side search (title, original file name, matter number/title, client, category) + filters + sorting + pagination (matters and clients from Phase 2) |
| Matter activity — *document events* | ✅ uploaded / version added / details updated / archived / restored / downloaded in the timeline (task events from Phase 2; signing events Phase 5) |
| Client document requests, client uploads, review, corrections | ⬜ Phase 4 — `origin = client` prepared |
| Administrator / client signing, signed PDFs, signer data | ⬜ Phase 5 — immutable versions to reference, `kind = signed` prepared |
| Link expiry / revocation | ⬜ Phase 4 |
| One signer per PDF, no client accounts, no external signing platform | ✅ Respected: no accounts, no external services; one-signer rule will reference a version |

---

## 3. Architecture

The three-tier architecture (Next.js → Laravel REST → PostgreSQL) and Phase 2 conventions are unchanged.

| Component | Responsibility |
| --- | --- |
| `App\Services\Documents\PdfInspector` | Byte-level validation: libmagic MIME, `%PDF-` signature, `%%EOF` + `startxref` (truncation), `/Encrypt` (unsupported), empty file |
| `App\Services\Documents\DocumentStorage` | Private disk access; generates every path; stages and verifies copies (size + SHA-256); moves into a never-used final path; refuses overwrites |
| `App\Services\Documents\DocumentService` | Create document (v1), add version, update metadata, archive/restore, download audit — each in one transaction with its activity event; cleanup on failure |
| `DocumentPolicy` | Access inherits from the matter (`view`/`update` on the matter) and requires an administrator |
| Controllers | `DocumentController` (library, matter list, store, show, update, archive, restore), `DocumentVersionController` (history, new version, show), `DocumentFileController` (preview, download) — thin |
| Activity | Existing `ActivityLogger` and append-only `activity_events`; new document event types |

Upload pipeline (files cannot join a database transaction, so ordering provides the guarantees):

```
validate (Form Request) → SHA-256 of upload → stage _staging/{uuid}.part → verify size + SHA-256
→ move to {matter_id}/{document_uuid}/{version_uuid}.pdf (fresh, never overwritten)
→ DB transaction { lock matter (→ document), duplicate check, version number, rows, activity event }
→ on any failure: delete only THIS request's new final file; staging file always removed
```

Result: a successful response always points to an existing, verified file. A failure leaves no metadata and no file, and
already committed versions are never touched. All three failure points are tested (§12).

---

## 4. Database

Migration `2026_11_18_100000_create_documents_tables` (additive; no existing table changed) was applied to the development
database with `php artisan migrate` (no reset). It is applied freshly to `amanet_test` (PHPUnit) and `amanet_e2e` (Playwright).

**`documents`**: `uuid` (unique), `matter_id` → matters **RESTRICT**, `title` (non-blank), `description`, `category`
(CHECK 7 values), `status` (`active`/`archived`, CHECK), `origin` (`admin`/`client`, CHECK, server-assigned), `current_version_id`,
`latest_version_number` (counter), `uploaded_by` → users SET NULL, `archived_at`, `lock_version`, timestamps.
CHECKs: `(status='archived') = (archived_at IS NOT NULL)`, `(current_version_id IS NULL) = (latest_version_number = 0)`, lock version ≥ 1.
Indexes: `(matter_id, status)`, `category`, `status`, `created_at`, `updated_at`.

**`document_versions`** (immutable): `uuid` (unique), `document_id` → documents **RESTRICT**, `version_number` (≥ 1,
**UNIQUE per document**), `kind` (`upload`/`signed`), `origin`, `original_filename` (non-blank, indexed), `storage_disk`,
`storage_path` (**unique**, CHECK `^[0-9]+/[0-9a-f-]{36}/[0-9a-f-]{36}\.pdf$`), `mime_type` (CHECK `application/pdf`),
`file_size` (> 0), `sha256_checksum` (CHECK 64 lower-case hex, indexed), `uploaded_by` + `uploaded_by_name` (snapshot, no FK),
`created_at`. Trigger `document_versions_no_update_delete` rejects every UPDATE/DELETE.

**Circular reference, robustly:** `documents (current_version_id, id) REFERENCES document_versions (id, document_id)` via
`UNIQUE (id, document_id)`. The current version can only be a version of the same document (tested). The document is inserted
with `current_version_id` NULL, then the version is inserted, then the pointer is set, all in one transaction.

**Why the uploader on versions has no FK:** `ON DELETE SET NULL` would be an UPDATE of an immutable row. The id plus a name
snapshot (as in `activity_events`) keeps the record and allows administrator accounts to be removed.

---

## 5. Backend API

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/api/documents` | library: search, filters, sorting, pagination |
| GET / POST | `/api/matters/{matter}/documents` | matter list · upload (multipart) |
| GET / PATCH | `/api/matters/{matter}/documents/{document}` | detail · metadata edit (`lock_version`) |
| POST | `…/{document}/archive` · `…/restore` | document lifecycle |
| GET / POST | `…/{document}/versions` | history (newest first) · new version (multipart) |
| GET | `…/versions/{version}` · `/preview` · `/download` | metadata · inline PDF · attachment (audited) |

Deviation from the suggested design: archive/restore endpoints were added for the document lifecycle. Everything else follows
the suggested paths. Routes use parent-scoped bindings (matter → document → version). Form Requests:
`StoreDocumentRequest`, `StoreDocumentVersionRequest`, `UpdateDocumentRequest`, `DocumentLifecycleRequest`,
`IndexDocumentsRequest`, with a shared `PdfUploadRules` trait. Server-managed fields are rejected with 422 `prohibited`:
`storage_path`, `storage_disk`, `origin`, `uploaded_by`, `version_number`, `sha256_checksum`, `mime_type`, `file_size`,
`current_version_id`, `kind`, `uuid`, `matter_id`, `status`, `id`. Resources: `DocumentResource`, `DocumentVersionResource`
(never serialise disk or path). A body above PHP's `post_max_size` answers **413** JSON. Full contract:
[`api-conventions.md`](api-conventions.md#endpoints-phase-3--documents).

---

## 6. File storage

- **Disk** `documents` (`config/filesystems.php`): local driver, root `storage/app/private/documents`, `visibility private`,
  **`serve => false`**. Laravel's default `local` disk had `serve => true`, which would allow signed `/storage` URLs, so that was
  switched off too. Nothing is stored under `public/` or `storage/app/public`. The path is relative to `storage_path()` (no
  hard-coded Windows path). `DOCUMENTS_STORAGE_DIR` selects a sub-folder, validated against `[a-z0-9_-]`.
- **Isolation:** PHPUnit uses `Storage::fake('documents')`. The concurrency test uses `phpunit-concurrency`, removed afterwards.
  The E2E API uses `e2e-documents`, wiped by global-setup under a name guard. Development files live in `documents`.
- **Naming:** `{matter_id}/{document_uuid}/{version_uuid}.pdf`, generated server-side. The original file name is sanitised
  metadata only: path parts, control characters and header delimiters removed, ≤ 180 + `.pdf`.
- **Validation:** `required|file|max:{DOCUMENTS_MAX_UPLOAD_MB×1024}|extensions:pdf` plus `PdfInspector`. The default is 20 MB
  (`DOCUMENTS_MAX_UPLOAD_MB`). PHP limits are raised to `upload_max_filesize = 25M` / `post_max_size = 30M` by
  `setup-windows.bat` (applied to Laragon's `php.ini`, backup kept).
- **Checksums:** SHA-256 of the upload, re-verified on the staged copy, stored per version, and recomputed on every
  preview/download. A mismatch answers 409 and logs a critical entry.
- **Failure cleanup:** see §3. A missing file answers 404 "could not be found" without paths.
- **Limitations:** a single local disk (no replication or backups configured for development); no malware scanning.

---

## 7. Document version control

- **Numbering:** server-only. `latest_version_number + 1` under `SELECT … FOR UPDATE` on the document row (lock order matter →
  document, as everywhere), with `UNIQUE (document_id, version_number)` as backstop. No client-supplied numbers.
- **Concurrency verified:** `DocumentVersionConcurrencyTest` runs 4 independent PHP processes × 5 uploads of one document through
  the real service and storage. Result: versions 1–20, no duplicates, no gaps, every file's SHA-256 intact, the counter and
  current version correct, no staging leftovers.
- **Immutability:** DB trigger, model guard and no update endpoint for versions. Metadata edits never touch version rows
  (asserted byte-for-byte on the row).
- **Current version:** `documents.current_version_id`, composite FK; history marks `is_current`.
- **Duplicate policy:** (a) a new **version** identical to the **current** version is rejected (422 "identical to the current
  version (vN)"); reverting to an older content is allowed as a new version. (b) A new **document** whose file already exists in
  the **same matter** (any version) is rejected with a pointer to it. This runs under the matter row lock, so double submissions
  and retries cannot create twins. Older versions are never removed because of a checksum match.

---

## 8. Frontend

| Item | Description |
| --- | --- |
| `/documents` | Library: search, filters (category, matter, client, status, upload date range), 5 sorts, pagination, all in the URL via `useUrlFilters` (the Phase 2 race-free implementation); table on desktop, cards on phones; Upload PDF (choose an active matter) |
| `/matters/[id]` → **Documents** tab | Count badge, Upload PDF, search, category and status filters, list, empty state ("No documents have been uploaded to this matter yet."), read-only for archived matters |
| `/matters/[id]/documents/[documentId]` | Header (category, current version, matter, client), Preview / Download / New version / Edit details / Archive, read-only banner, **version history** (label, current badge, file name, size, date, uploader, SHA-256 prefix, Preview / Download per version), details card |
| `DocumentUploadDialog` | New document or new version; target matter shown; drag-and-drop or file picker; browser prechecks (extension, size, `%PDF-`); file name and size; **real upload progress** from `XMLHttpRequest` byte counts (no simulated percentages), then "Verifying and storing…"; success only after the API confirms; double-submit guard |
| `PdfViewerDialog` | PDF.js 6.5.438: fetches the authorised preview with the session cookie (no credentials in URLs, no object URL kept), renders to `<canvas>` only (no annotation, form or script layer), lazy page rendering, page navigation, "Page x of y", zoom ± / fit width, download, explicit errors (session, missing, integrity, damaged/encrypted, network) with retry; releases the document on close |
| Shared | `DocumentList`, `DocumentCategoryBadge`, `DocumentRowActions`, `useDownload` (authenticated fetch → save; refreshes the timeline), metadata edit dialog |
| Navigation | "Documents" is now a live sidebar section (no longer "Coming soon") |

**State:** TanStack Query; after uploads, versions, edits and archive actions, `invalidateWorkspace()` refreshes the library,
matter lists and counters, document detail, versions and timeline. Downloads refresh the timeline. Metadata edits send the
`lock_version` loaded when the dialog opened.

**Responsive and accessible:** dialogs are bottom sheets on phones; tables become cards; the viewer scrolls inside its own
container. The E2E suite checks for horizontal overflow on the documents tab, document detail and library on a Pixel 7. There are
labelled controls, `role="toolbar"`, `aria-live` page and progress status, `role="img"` page canvases, `role="alert"` errors and
keyboard-operable dialogs (Escape and close tested). **Dependency added:** only `pdfjs-dist@6.5.438` (exact). Its worker and fonts
are copied to `public/pdfjs` by `postinstall`, so CSP `'self'` still applies. PDF.js 6 contains no `eval` / `new Function`.

---

## 9. Search

Server-side, parameter-bound, allow-listed (`IndexDocumentsRequest`): `search` (≤ 100 chars, LIKE-escaped) over document
title, versions' original file names, matter number, matter title, client name and category labels. Filters: `category`,
`status` (`active` default / `archived` / `all`), `matter_id`, `client_id`, `uploaded_from` / `uploaded_to`. Sorts:
`updated_at`, `created_at`, `title`, `matter_number` (join), `category`, with `id` as tiebreaker. Paginated with eager loading
(current version, matter, client), so there are no N+1 queries. Indexes: `documents(matter_id, status)`, `category`, `status`,
`created_at`, `updated_at`, `document_versions(original_filename)`, `(sha256_checksum)`. PDF **contents** are not indexed, and the
UI says so.

---

## 10. Security review

| Area | Result |
| --- | --- |
| Authentication | All 12 document routes behind `auth:sanctum` + `admin` + `throttle:api`; **401 verified for every one** (`DocumentAccessControlTest`) and in E2E with an empty browser context |
| Authorisation | `DocumentPolicy` inherits from the matter; Form Requests and `Gate::authorize` on every action |
| Nested access | Scoped bindings: document of another matter, version of another document, unknown version → **404** (PHPUnit + E2E) |
| Archived state | Archived matter or document: uploads, versions, edits → **409**; viewing, preview and download allowed |
| File exposure | No public directory; `serve` disabled on both local disks; `/storage/...` guesses → 404 (PHPUnit, E2E, manual); no paths in any JSON, error or history entry (asserted) |
| Path manipulation | Paths generated server-side, DB CHECK on the format, client path fields rejected (422), traversal file names sanitised (tested) |
| Upload validation | Wrong extension, non-PDF content, empty, truncated, encrypted, oversized, framework-fake files rejected with clear messages; nothing stored (tested) |
| Response headers | `application/pdf`, RFC 5987 file name with ASCII fallback (header injection impossible: delimiters removed, tested), `nosniff`, `no-store`, `CSP default-src 'none'; sandbox`, `X-Frame-Options: DENY`, `CORP same-site` |
| PDF handling | Server never parses or executes PDF content. Client renders to canvas only; PDF scripting, links, forms and attachments are not activated |
| Integrity | Immutable versions, SHA-256 re-verified on every read (tamper → 409, tested) |
| Known limitations | No malware scanning; PDF.js WebAssembly decoders not enabled; single-role model (policy-ready); CSP still allows `'unsafe-inline'` scripts (Phase 7) |

---

## 11. Activity history

New event types: `document.uploaded`, `document.version_added`, `document.updated`, `document.archived`,
`document.restored`, `document.downloaded`. Catalogued for Phases 4–6: `document.submitted`,
`document.correction_requested` (plus the existing `document.requested`, `document.reviewed`, `signing.*`, `link.revoked`).

Metadata contains only non-confidential facts: document id, version id and number, category, sanitised file name, size, SHA-256.
For edits it holds the changed fields (description only as "changed"). There are **no paths and no content**.

Uploads, versions, edits and archive/restore write their event in the **same transaction**. If the event fails, the change
rolls back and the new file is removed (tested). **Audit policy:** every download is recorded; inline previews are not, to keep
the timeline meaningful. The timeline shows readable labels and icons (e.g. "Document uploaded", "New document version added",
"Document downloaded") with the administrator and time.

---

## 12. Testing

All results were **executed** on 16 November 2026 on the target machine.

### Final full regression — `verify-windows.bat` (`verification-logs/verify-20261116-190747.log`)

| Step | Result |
| --- | --- |
| PHP `pdo_pgsql` · `composer validate` · migrate (dev, additive) · seed idempotency · route list | ✅ PASS |
| **PHPUnit** (`php artisan test`, database `amanet_test`) | ✅ **140 passed, 975 assertions** |
| Pint | ✅ PASS |
| ESLint | ✅ PASS (0 problems) |
| TypeScript (`next typegen && tsc --noEmit`) | ✅ PASS |
| Production build | ✅ PASS (incl. `/documents`, `/matters/[id]/documents/[documentId]`) |
| **Playwright** (own servers :8011/:3011, `amanet_e2e`, `e2e-documents`) | ✅ **39 passed** (5.5 min) |

### Comparison with the Phase 2 baseline

| Suite | Phase 2 | Phase 3 | Notes |
| --- | --- | --- | --- |
| PHPUnit | 110 / 597 assertions | **140 / 975** (+30) | all 110 earlier tests unchanged and passing |
| Playwright | 34 | **39** (+5 tests, scenarios A–H) | one Phase 1 assertion updated: it checked that the *planned* "Documents" item was **not** a link; Phase 3 made it a working section, so it now checks the next planned item ("Client requests") and that Documents links to `/documents` |

### New PHPUnit tests (`tests/Feature/Phase3`)

| Class | Tests | Covers |
| --- | --- | --- |
| `DocumentUploadTest` | 9 | valid upload (rows, private file bytes, path layout, checksum, event, no internal fields in JSON), title default + traversal-safe file name, non-PDF/truncated/encrypted/empty/wrong-extension/missing file, oversized (configurable limit), prohibited server fields, archived matter 409 + missing matter 404 with no file left, duplicate in the same matter vs another matter, 401, framework fake file |
| `DocumentVersionTest` | 7 | v2/v3 sequence + history order + `is_current` + v1 bytes intact, identical-to-current rejected / revert allowed, immutability (model + trigger for UPDATE and DELETE), unique number + composite current-version FK + path CHECK, metadata edit (versions untouched, safe history, stale 409, prohibited fields, file in PATCH refused), archived document/matter read-only but readable, cross-matter/cross-document/unknown version 404 |
| `DocumentFileAccessTest` | 4 | exact bytes per version (v1 is not the latest), headers, UTF-8 file name + ASCII fallback, previews not audited / downloads audited, `/storage` guesses blocked, tampered file 409 + missing file 404 without path |
| `DocumentAccessControlTest` | 1 | 401 for all 12 document routes, never PDF bytes |
| `DocumentSearchTest` | 4 | search by title, file name, matter number, matter title, client, category label, literal `%`; filters (status default/archived/all, category, matter, client, date range); sorts and pagination; matter scoping; invalid filters 422 |
| `DocumentIntegrityTest` | 4 | storage failure → no metadata; history failure on create/version/edit → rollback, new file removed, v1 intact, no staging; move failure → nothing left; FK orphan prevention |
| `DocumentVersionConcurrencyTest` | 1 | **4 processes × 5 uploads → versions 1–20, unique, gap-free, files intact** |

### New Playwright tests

| Spec | Scenarios |
| --- | --- |
| `phase3-documents.spec.ts` (desktop, 4 tests) | **A** upload via matter tab, toast, list, reload persistence, tab count · **B** preview: real 3-page PDF rendered (canvas text pixels), "Page 1 of 3", next page, zoom, Escape, UI still usable · **C** download: file name + SHA-256 equal to the upload · **D** v2 upload, history (2 rows, current), preview v1 and v2 (page counts), download both (distinct, each identical to its upload), duplicate refused · **G** timeline labels, version description, downloads, no confidential text · **E** library search, matter + category filter, sort, URL sync, file-name search, reload keeps filters · **F** anonymous 401 (preview, download, detail), `/storage` guess, cross-matter 404, invalid version 404, no `storage_path` in JSON, archived matter: list visible, no upload button, API upload 409, nothing stored |
| `phase3-mobile.spec.ts` (Pixel 7, 1 test) | **H** upload on a phone (dialog fits), readable card, preview renders and closes, detail and version history, no horizontal overflow on tab, detail and library |

### Manual browser and file checks (development stack)

`verification-logs/phase3-manual-check-20261116.log`:

- After raising PHP limits, the dev API was restarted via `start-windows.bat`.
- On matter `LEX-2026-0012`, v1 (3 pages) and v2 were uploaded through the dev API using the SPA's session and CSRF flow.
- In the browser, the expired session redirected to login, then the document page loaded, and the v1 preview rendered
  ("Page 1 of 3", text pixels on canvas).
- Downloads of v1 and v2 were byte-identical with correct headers. Anonymous access returned 401 and cross-matter access 404.
  Exactly 2 private files existed with 0 staging leftovers, and the `/storage` guess returned 404.
- Database rows and the history match. The verification matter was archived afterwards.

---

## 13. Bugs and fixes

| # | Issue | Root cause | Fix | Verified |
| --- | --- | --- | --- | --- |
| 1 | 20 MB uploads impossible in the dev environment | Laragon PHP defaults `upload_max_filesize=2M`, `post_max_size=8M` | `setup-windows.bat` sets 25M/30M (backup kept); applied locally; JSON 413 for oversize bodies | `php -r ini_get` 25M/30M; manual upload |
| 2 | Private files could become servable | Laravel's `local` disk has `serve => true` (signed `/storage` route) and its root contains `private/` | Dedicated `documents` disk with `serve => false`; `local` serving disabled too | `/storage` guesses 404 (PHPUnit, E2E, manual) |
| 3 | `ON DELETE SET NULL` on the version uploader would conflict with immutability | SET NULL is an UPDATE, blocked by the trigger | Uploader stored as id + name snapshot without FK | `DocumentVersionTest` immutability |
| 4 | TypeScript: `PDFDocumentProxy.destroy` missing | PDF.js 6 moved cleanup to the loading task | `loadingTask.destroy()` on close/unmount | typecheck + E2E |
| 5 | ESLint reported 1,574 problems | It linted the copied minified PDF.js worker in `public/pdfjs` | `public/pdfjs/**` ignored (vendor, git-ignored) | lint 0 problems |
| 6 | Unauthenticated-access test saw 200 | Test harness: Laravel re-applies `actingAs` to later requests in the same test | Separate test class that never authenticates (covers all 12 routes) | PHPUnit |
| 7 | Playwright strict-mode violations | The row's "Open …" link also contains the title | `exact: true` | E2E 39/39 |
| 8 | Canvas "has text" check could pass on a blank canvas | Unrendered canvases are transparent black | Check opaque dark pixels only | E2E |
| 9 | Dev API still enforcing 2 MB after the php.ini change | Its PHP process started before the change | Restarted only this project's API process via `start-windows.bat` | manual upload |

---

## 14. Git information

Branch `feature/phase-3-document-management`, created from `main` at `840f8e0` (Phase 2 merge):

- `1e9dfe6` Phase 3 backend: private PDF storage, immutable versions, document API
- `ef4235d` Phase 3 frontend: document library, matter Documents tab, PDF viewer, version history
- documentation commit (this report, README, architecture, data model, API conventions, phases) — see `git log`

Not committed (git-ignored): `.env`, `.env.local`, uploaded PDFs (`storage/app/private/**`), `frontend/public/pdfjs`, logs,
test artefacts, `vendor/`, `node_modules/`.

---

## 15. Phase 4 readiness

**Ready**, with these known additions:

- **Secure external upload links / client document requests:** new tables `document_requests` (matter, client, instructions,
  status, due date) and `secure_access_links` (hashed token, purpose, expiry, revocation, use counts), as designed in
  `data-model.md`. Public endpoints go under `/api/public/*` with their own rate limiter and minimal resources (never internal
  notes).
- **Client submissions:** `DocumentService` and `DocumentStorage` already take the file, validate it with the same
  `PdfInspector`, and create a document or version. Phase 4 adds an actor variant with `origin = client` (the column, CHECK
  and enum exist) and an uploader snapshot from the request instead of a user.
- **Review and correction requests:** a review status belongs to the **request/submission**, not to `documents.status`
  (deliberately kept as lifecycle only). The events `document.submitted`, `document.reviewed` and
  `document.correction_requested` are catalogued.
- **Outstanding document requirements:** derived from open `document_requests` per matter (dashboard counter in Phase 4).
- **Signing (Phase 5):** `signing_requests.document_version_id` will reference an immutable version. The signed output is
  stored as a new version with `kind = signed`, so the source is never overwritten.

Decisions for Phase 4: link lifetime default, maximum uses per link, whether a client upload creates a new document or a
version of a requested one, and email delivery (Phase 6) vs. copying links manually in Phase 4.

---

## 16. Final acceptance checklist

**Document management**
- [x] Upload a real PDF (UI E2E + manual) · [x] assigned to the correct matter · [x] metadata in PostgreSQL · [x] stored privately
- [x] Visible in the matter workspace · [x] visible in the global library · [x] metadata editable safely (lock_version, history)
- [x] Archived matter restrictions enforced (409; read-only UI)

**PDF preview**
- [x] Real PDF content rendered (canvas pixel check) · [x] multipage · [x] navigation and zoom · [x] responsive (Pixel 7)
- [x] Unauthorised preview rejected (401) · [x] errors handled (session, missing, integrity, damaged, encrypted, network, retry)

**PDF download**
- [x] Latest and historical versions download correctly · [x] integrity verified (SHA-256, PHPUnit + E2E + manual)
- [x] Correct headers · [x] private paths hidden

**Version control**
- [x] New version upload · [x] unique, sequential numbers · [x] previous versions intact · [x] current version identified
- [x] History UI · [x] concurrent creation safe (4-process test) · [x] duplicate policy · [x] versions immutable (trigger)

**Search and filters**
- [x] Global search · [x] matter-level search · [x] category filter · [x] client and matter filters · [x] sorting
- [x] pagination · [x] URL synchronisation (reload keeps filters)

**Activity**
- [x] Upload, version and metadata events · [x] timeline displays them · [x] append-only preserved · [x] no sensitive content

**Security**
- [x] Admin authentication on all routes · [x] backend authorisation · [x] cross-matter and cross-document access blocked
- [x] Files not publicly accessible · [x] invalid files rejected · [x] paths not manipulable · [x] failures leave no inconsistent records

**UI/UX**
- [x] Documents library · [x] matter Documents tab · [x] functional upload with real progress · [x] responsive preview
- [x] Version history · [x] loading, empty and error states · [x] accessible controls · [x] no fake functionality

**Testing and delivery**
- [x] Phase 1, 2 and 3 tests pass (140 PHPUnit, 39 Playwright) · [x] TypeScript · [x] ESLint · [x] Pint · [x] production build
- [x] Windows verification 11/11 · [x] manual upload/preview/download checks · [x] git state documented · [x] documentation complete

**Remaining (documented, not failing):** malware scanning is not implemented; full-text PDF search is not offered; PDF.js
WebAssembly decoders are not enabled; Phase 4–7 features are not started.
