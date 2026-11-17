# Phase 5 Implementation Report — Electronic PDF Signing

Branch `feature/phase-5-pdf-signing` (from `main` at `d4fbdbe`). Not merged into `main`; not pushed.
All data used during development and verification is **fictional**.

---

## 1. Executive Summary

**Completed**

- Administrator signing in the workspace: "Sign PDF" on a document (create + sign in one step) and "Sign now" on a prepared
  administrator request.
- Passwordless client signing through a secure, expiring, revocable, one-time link at `/client-sign#<token>`.
- Drawn signatures (mouse, finger or stylus) and typed signatures. The signer places them on any page with a live preview.
- Exactly **one designated signer per PDF**, enforced by the service layer and the database.
- Real progress tracking: Ready → Sent (only after the administrator confirms sharing) → Viewed → Started → Signed, plus Cancelled.
  All timestamps come from the server.
- Signed PDFs are generated on the server (FPDI + FPDF, no third-party signing platform) and verified. They are stored as a
  **new immutable document version** (`kind = signed`); the original version never changes.
- An immutable signature record per request: designated signer, confirmed name, method, page and placement, source and
  signed SHA-256, versioned consent text, server time, IP address and user agent.
- Activity events for every signing step, a global `/signatures` page, a Signatures tab per matter, a request page with a
  progress timeline, a link card and an evidence card, and dashboard counters.
- 28 new PHPUnit tests and 7 new Playwright tests (6 desktop, 1 on a Pixel 7), plus an updated navigation test.
- Final full Windows verification on `afd7aa8`: all 11 steps passed.
  - PHPUnit: **201 passed (2,809 assertions)**, which is the 173 pre-existing tests plus the 28 new ones.
  - Playwright: **53 passed**, which is the 46 pre-existing tests plus the 7 new ones.
- Manual checks: all four combinations (administrator or client, drawn or typed) were signed on the development stack.
  Each signed PDF was rendered and inspected, and its SHA-256 was verified (sections 16–19).
- Verification and finalisation found four issues, all fixed and re-verified (section 20):
  - a genuine concurrency bug in the temporary output file;
  - an end-to-end time budget that was too tight;
  - a keyboard gap in the signature-method selector;
  - untested PDF-compatibility claims.

**Partial / deliberately out of scope**

- No e-mail delivery: the administrator copies the link and confirms sharing. Automated invitations, reminders and
  notifications belong to Phase 6.
- Signing is limited to PDFs the built-in parser can import (section 5): compressed cross-reference streams, forms,
  annotations, existing digital signatures and encrypted files are refused with a clear message.

**Known limitations** (details in section 23)

- This is an application-level electronic signature record, **not** a certificate-based, qualified, PAdES or eIDAS signature.
- Client identity rests on possession of the link plus the confirmed name. There is no independent identity verification.
- No malware scanning; PDFs are validated structurally, as in Phases 3 and 4.

## 2. Original Assignment Mapping

| Assignment requirement | Implementation |
| --- | --- |
| Admin signing | `POST /api/matters/{m}/documents/{d}/sign` ("Sign PDF" dialog) and `POST …/signing-requests/{id}/admin-sign` ("Sign now"); the signer is always the authenticated administrator |
| Client signing | `/client-sign#token` page plus the isolated `/api/public/signing*` API, with no account and no cookies |
| One signer per PDF | One `signer_type` with exactly one of `signer_user_id` / `client_id` (CHECK constraints). A partial unique index allows one active request per document. Signed versions (`kind = signed`) can never be a source (trigger). One signature record per request (unique) |
| No third-party signing platform | FPDI 2.6.10 + FPDF 1.8.2 (MIT), running in-process in Laravel. No external calls |
| Signature progress | `signing_requests.status` and `sent_at / viewed_at / started_at / signed_at`, shown in the progress timeline and in activity |
| Signed PDF records | `document_versions` row with `kind = signed` (immutable, SHA-256) plus `signature_records` (immutable) |
| Secure signing links | `secure_access_links` with `purpose = document_signing`: 256-bit token stored only as a SHA-256 hash, expiry, revocation, rotation, consumed on completion |
| Matter activity | 10 new event types (section 14) in the append-only `activity_events` |

**Automated e-mail delivery remains Phase 6.** Phase 5 never sends or claims to send an e-mail. "Sent" means the administrator
confirmed that they shared the link themselves (`delivery_channel = manual`, `delivery_status = shared_manually`).

## 3. Architecture

```
Admin (Next.js, Sanctum session)                         Client (no account; /client-sign#<token>)
  Document page "Sign PDF" ─► eligibility (pages+consent)   page reads the fragment (never sent to a server)
  Signatures tab / request page                              GET  /api/public/signing           (info, masked e-mail)
     create request [+ link] ─► link shown ONCE ─► copy      GET  /api/public/signing/document  (PDF bytes, sha-checked)
     mark shared ─► Sent                                     POST /api/public/signing/view      (rendered → Viewed)
     rotate / revoke / cancel                                POST /api/public/signing/start     ("Start signing" → Started)
                                                             POST /api/public/signing/complete  (signature, placement, consent)
            │                                                        │  header X-Signing-Token, credentials: omit
            ▼                                                        ▼
  SigningRequestController ─► SigningRequestService      AuthorizePublicSigningLink ─► PublicSigningLinkResolver
            └──────────────► SigningService::complete() ◄────────────┘
                 1 validate input (appearance, placement, consent, confirmed name)
                 2 verify source file SHA-256; PdfSigningService::inspect (page sizes)
                 3 PdfSigningService::sign → temp file → verifyOutput (re-parse, pages, sizes, caption)
                 4 DocumentService::persistLocalFile (private staging, SHA-256, final path) + ONE transaction:
                   lock matter → document → request (→ link); re-check archive, current version, status, link;
                   append signed version; insert signature record; request → signed; consume link; 2 activity events
                 5 any failure → rollback + delete this attempt's file; nothing reports success
PostgreSQL: signing_requests, signature_records, secure_access_links.signing_request_id, triggers and CHECKs (section 4)
```

- **Laravel** owns every rule: services in `app/Services/Signing/`, form requests in `app/Http/Requests/Signing/`, resources,
  a public middleware and enums. `SigningLifecycle` cancels unfinished requests when a matter or document is archived or a
  newer version is added.
- **Next.js** renders PDFs with PDF.js 6.5.438 (canvas only) and captures the signature with Pointer Events. It sends
  normalised geometry and never sends an image.
- **PostgreSQL** enforces matter/document/version consistency (composite FKs), signer exclusivity, status/timestamp
  consistency, one active request per document, immutability of evidence, and link targets.

## 4. Database

Migration `2026_11_20_100000_create_signing_tables.php` (additive; applied to the development database with `php artisan migrate`).

### `signing_requests`

| Column | Type | Notes |
| --- | --- | --- |
| id, uuid | bigint, uuid unique | uuid is the caption reference (`LX-` + 12 hex) |
| matter_id | FK matters (restrict) | |
| document_id | bigint | composite FK `(document_id, matter_id)` → `documents (id, matter_id)` |
| document_version_id | bigint | the immutable **source** version; composite FK `(…, document_id)` → `document_versions` |
| signer_type | varchar(20) | `admin` \| `client` (CHECK) |
| signer_user_id | FK users (restrict), null | required iff admin (CHECK) |
| client_id | FK clients (restrict), null | required iff client (CHECK); must be the matter's client (trigger) |
| signer_name, signer_email | snapshot | name not blank (CHECK) |
| message | text null | shown to the signer |
| status | varchar(20) | `ready, sent, viewed, started, signed, cancelled` (CHECK) |
| delivery_channel | varchar(20) null | `manual` (Phase 5); `email` reserved for Phase 6 |
| delivery_status | varchar(30) | `not_shared`, `shared_manually` |
| sent_at, viewed_at, started_at, signed_at, cancelled_at | timestamptz | server time |
| cancellation_reason | varchar(30) null | `manual, matter_archived, document_archived, source_superseded` |
| cancelled_by, created_by | FK users (set null) | |
| signed_document_version_id | bigint unique null | composite FK → `document_versions (id, document_id)` |
| lock_version | int ≥ 1 | optimistic concurrency (409 on stale actions) |

CHECK constraints:
- `signing_requests_admin_signer` / `_client_signer`: exactly one designated signer.
- `_signed_consistency`: signed ⇔ `signed_at` and the signed version are set.
- `_cancelled_consistency`: cancelled ⇔ `cancelled_at` and a reason are set.
- `_sent/_viewed/_started_has_time`: each of those statuses has its timestamp.
- `_delivery_consistency`: `sent_at`, the channel and the delivery status agree.
- `_progress_order`: sent ≤ viewed ≤ started ≤ signed.
- `_output_differs`: the signed version differs from the source.
- Plus value lists for every enum column.

Indexes:
- `signing_requests_one_active_per_document`: partial unique on `document_id` WHERE status IN (ready, sent, viewed, started).
- `(matter_id, status)`, `status`, `document_id`, `created_at`, `updated_at`.
- `unique (id, matter_id)`: target of the links' composite FK.

Triggers:
- `signing_requests_insert_guard`: the source must be `kind = upload`; a client signer must be the matter's client.
- `signing_requests_update_guard`: matter, document, source, signer type, signer ids, name and e-mail can never change;
  `signed` and `cancelled` rows are final; deletion is refused.

### `signature_records` (immutable: UPDATE/DELETE trigger)

- Request and document: `signing_request_id` (unique, FK restrict) and `document_id`.
- Signer snapshot: `signer_type`, `signer_user_id`, `signer_name`, `signer_email`, plus `confirmed_name`.
- Signature: `signature_method` (`drawn|typed`), `page_number ≥ 1`, and `placement` jsonb (normalised x/y/width, page size in
  points, rectangle in points).
- Versions: `source_document_version_id` and `signed_document_version_id` (unique). Both have composite FKs to the same
  document.
- Checksums: `source_sha256` and `signed_sha256` (hex-64, must differ).
- Consent and time: `consent_version`, `consent_text`, `consent_accepted_at`, `signed_at`.
- Client details: `ip_address`, `user_agent`, `created_at`.

### `secure_access_links` (extended)

- New nullable `signing_request_id`, with composite FK `(signing_request_id, matter_id)` → `signing_requests (id, matter_id)`.
- `secure_access_links_target` replaces the Phase 4 upload-only CHECK: an upload link targets only a document request, and
  a signing link targets only a signing request.
- `secure_access_links_one_usable_signing_link`: partial unique index allowing one unrevoked, unconsumed signing link per
  request.

### Lifecycle model

```
ready ──mark shared──► sent ──page rendered──► viewed ──"Start signing"──► started ──complete──► signed (final)
  │  └─(client opens before sharing is confirmed: ready ──► viewed; delivery stays not_shared)
  └─ any active status ──cancel / matter archived / document archived / new version──► cancelled (final)
Administrator requests: ready ──sign──► signed (no link, no sent/viewed/started).
```

## 5. PDF Signing Engine

| Library | Version | License | Use |
| --- | --- | --- | --- |
| setasign/fpdi | 2.6.10 | MIT | imports every page of the source PDF as a template (page size, rotation, order) |
| setasign/fpdf | 1.8.2 | MIT | writes the new PDF: imported pages + vector signature + caption |
| pdfjs-dist (frontend) | 6.5.438 | Apache-2.0 | renders pages for review and placement (unchanged from Phase 3) |

- **Compatibility.** The source must be a PDF that FPDI's free parser can import: classic cross-reference tables, any
  page size or rotation, any number of pages. `PdfSigningService::inspect()` opens the file once to collect each page's
  displayed size; FPDI applies `/Rotate`, so PDF.js and the engine use the same coordinate frame.
- **Signature generation.**
  - Drawn signatures are vector strokes: normalised points, drawn as PDF line segments with round caps and joins
    (`1 J 1 j`). No raster image, SVG or GD.
  - Typed signatures use the standard Times-Italic font. The name must convert to cp1252 without loss.
  - Below each signature, a caption in Helvetica reads `Electronically signed by {designated name}` followed by
    `{UTC time}  ·  Ref LX-{12 hex of the request uuid}`.
- **Page handling.** Every page is imported in order with its original size and orientation, and only the chosen page gets
  the signature. Page count and sizes are re-checked after generation.
- **Placement conversion.**
  - The browser sends `page`, `x`, `y` and `width` as fractions of the displayed page.
  - `SignaturePlacement::fromInput` converts them to points: width between 100 and 320 pt, height = width × 0.36. The
    signature fills the top 68 % of the box and the caption the rest. The box is clamped inside the page with 0.5 pt
    tolerance.
  - The resulting rectangle is stored in the record (`rect_pt`).
- **Output verification.** The output is written uncompressed and re-parsed from memory with FPDI. Page count, each page size
  and the presence of the caption must all match. If verification fails, nothing is stored.
- **Unsupported PDF cases** are refused before signing, with a user-friendly message:
  - a 422 validation error on `document` for eligibility, request creation and "Sign PDF";
  - `422 pdf_unsupported` if the source fails later, inside the engine.

  Every case below is covered by the PHPUnit test `pdfs_the_engine_cannot_sign_faithfully_are_refused_with_an_explanation`.
  It uses realistic PDFs and asserts the specific message and that nothing was created or changed. The refused cases:
  - compressed cross-reference streams (PDF 1.5+ object streams; FPDI's free parser limit);
  - `/AcroForm` forms and `/Annots` annotations (flattening them would change their meaning);
  - existing `/ByteRange` digital signatures (re-writing would invalidate them);
  - encrypted files (already refused at upload in Phase 3);
  - files that fail to parse.

  Every refusal leaves no request, version or file behind.

  **Practical impact.** Many PDFs produced by current office suites and scanners use PDF 1.5+ cross-reference streams,
  and many legal templates contain form fields. Those files must be re-saved as PDF 1.4 / PDF/A-1b, or printed to PDF,
  before they can be signed here. Lifting this limit would need a parser that reads cross-reference streams, such as the
  commercial FPDI PDF-Parser add-on or another engine. That is a candidate for a later phase and was not implemented.

## 6. Signature Methods

- **Drawn**: a pointer-events pad (mouse, touch, pen) with Clear.
  - Limits: up to 60 strokes, 500 points per stroke and 4,000 points in total. At least 6 points are needed, and the drawing
    must span at least 8 % of the pad.
  - The server validates the stroke geometry again and draws it as vectors.
- **Typed**: a "Type" tab with a cursive preview.
  - The typed text must match the designated signer's name; the comparison ignores case, spacing and accents.
  - It must be representable in cp1252; otherwise the signer is asked to draw.
- **Both methods**:
  - The confirmed full name must match the designated signer. For administrators it is fixed to their own account name.
  - Consent is mandatory and never pre-checked.
  - The payload is limited to 256 KB (413 above). Signature payloads are excluded from flashed input and logs.

## 7. Signed PDF Storage

- **Source version**: the request pins `document_version_id`.
  - Only the **current** `upload` version of a non-archived document can be signed.
  - The source file's SHA-256 is re-verified before signing.
  - If a newer version arrives first, the request is cancelled (`source_superseded`), and completion re-checks under the
    document lock.
- **Signed version**:
  - Written by `DocumentService::persistLocalFile` to the private `documents` disk (`storage/app/private/documents/{matter}/
    {document-uuid}/{version-uuid}.pdf`), never under `public/`.
  - Appended as the next immutable `document_versions` row with `kind = signed`. It becomes the current version, and its
    origin is `admin` or `client`.
  - File name: `{original}-signed.pdf`.
- **SHA-256**: computed on the staged file, verified after the move, stored on the version and in the signature record (along
  with the source checksum). Every download and preview re-verifies it (Phase 3 behaviour).
- **Immutability**:
  - versions are protected by the Phase 3 trigger;
  - signature records have their own trigger;
  - a signed or cancelled request is final;
  - a signed version can never be a signing source.
- **Failure cleanup**:
  - Each attempt writes its PDF next to a unique temporary name reserved with `tempnam()`. The reservation is held until
    the attempt ends, so parallel attempts can never share a file (section 20).
  - The reservation and the temporary output are always deleted in `finally`.
  - If the database transaction fails, the newly stored file of that attempt, and only that file, is deleted.
  - Tests inject failures at each step and check that no temporary files are left behind (section 16).
- **Integrity verification**:
  - source SHA-256 before signing;
  - FPDI re-parse of the output;
  - staged and final SHA-256;
  - verification on every read.

## 8. Secure Links

- **Token generation**: `base64url(random_bytes(32))`, giving a 43-character, 256-bit CSPRNG token (shared
  `SecureAccessLinkService`).
- **Hashing**: only `SHA-256(token)` is stored (`token_hash`). The raw token is returned once in the creation response
  (`IssuedLink`) and is never stored, logged or written to activity.
- **Expiry**: 7 days by default (`SIGNING_LINK_EXPIRY_DAYS`); 1, 3, 7, 14 or 30 days can be chosen. An expired link
  returns 410 `link_expired`.
- **Revocation**: a manual revoke answers like an unknown link (404 `link_invalid`, no hint). Lifecycle closures (archive,
  cancellation) answer 410 `request_unavailable` or `request_cancelled`.
- **Rotation**: "Generate replacement link" revokes the previous usable link in the same transaction
  (`signing.link_rotated`). The partial unique index guarantees at most one usable signing link.
- **Purpose isolation**:
  - The resolver only accepts `purpose = document_signing` tokens. An upload token is "unknown" on the signing API and a
    signing token is unknown on the upload API, in both directions (E2E scenario G).
  - The CHECK ties each purpose to its target table.
- **One-time signing**: viewing does not use the link. It is consumed only inside the completion transaction, with a
  conditional update (`consumed_at IS NULL AND revoked_at IS NULL`). Afterwards it answers 410 `already_signed`.
- **Token leakage prevention**:
  - The token is in the URL **fragment** for the page and in the `X-Signing-Token` header for the API; never in a path,
    query, Referer, cookie or server log.
  - The page keeps it in memory only (no storage, no query cache) and calls the API with `credentials: "omit"`.
  - `/client-sign` is served with `Referrer-Policy: no-referrer` and `X-Robots-Tag: noindex`. API responses add
    `Cache-Control: no-store`.
  - The public group runs without session or CSRF middleware and enforces an Origin allow-list.
  - Rate limits: 120 views per minute per IP, and 10 completions per minute per IP (both configurable). Each token is
    also limited to 5 completions per minute; the limiter keys on the token's hash, never the token itself. After 20
    failed lookups an IP is locked out for 15 minutes. All of these are tested in `SigningSecurityTest`.

## 9. Signing Statuses

| Status | Meaning and exact transition |
| --- | --- |
| **Ready** (draft/ready) | Created; nothing has been shared. Generating or copying a link does **not** change it |
| **Sent** | Reached only through `POST …/mark-shared`. Allowed from Ready, for a client request with an active link. The administrator confirms in a dialog that they shared the link themselves. Sets `sent_at` and `delivery_channel = manual`. No e-mail is claimed |
| **Viewed** | `POST /public/signing/view`, sent by the signing page after the PDF actually rendered. Allowed from Ready or Sent; idempotent; never moves backwards. Opening before sharing is confirmed goes Ready → Viewed and leaves delivery as `not_shared` |
| **Started** | `POST /public/signing/start` when the signer presses "Start signing". Allowed only from Viewed; idempotent |
| **Signed** | Set only inside the completion transaction, after the signed PDF is stored and verified. Client: only from Started. Administrator: from Ready |
| **Cancelled** | Manual cancel of an active request, or automatic when the matter or document is archived or a newer version is added (`cancellation_reason`). Revokes usable links. Final |
| Expired / Revoked | Link states, not request states. The request stays active, and the administrator can issue a replacement link |

The service layer, the update trigger (finality) and the CHECKs (timestamps present and ordered) enforce these rules.
Concurrent or stale transitions return 409. Progress persists across reloads (E2E scenario F).

## 10. Administrator Signing

UI flow:
1. On the document page (`/matters/{m}/documents/{d}`), "Sign PDF" opens a dialog.
2. The dialog calls `GET …/signing-eligibility`, which returns the page sizes and the consent text, or the reason signing is
   unavailable (already signed, open client review, archived, open request, unsupported PDF).
3. The dialog shows:
   - the PDF on the left, with page navigation;
   - signature capture (Draw or Type), placement (click the page, drag the box, arrow keys) and size;
   - "Signing as {name} (your signed-in administrator account)";
   - the consent statement.
4. "Sign document" calls `POST …/documents/{d}/sign`. `SigningService::signNowAsAdmin` creates the administrator request
   and completes it in **one** transaction, so an abandoned attempt leaves nothing open. The app then redirects to the
   request page.

A prepared administrator request (created from the Signatures tab) is signed with "Sign now"
(`POST …/signing-requests/{id}/admin-sign`, with `lock_version`). Only the designated administrator can sign it. A client
request can never be signed by an administrator.

## 11. Client Signing

1. The administrator creates a client request (Signatures tab), optionally generating the link at once. The link is shown
   once with "Copy link".
2. The administrator shares the link and marks it as shared.
3. The client opens `/client-sign#token` with no account and no cookies:
   - The page shows the firm name, the document title, the designated signer, a masked e-mail and the message.
   - The client reviews every page; rendering records **Viewed**.
4. "Start signing" records **Started**. The client then draws or types, places the signature and confirms their full name
   and the consent statement.
5. "Sign document" completes the request. The success page confirms that the firm received the signed document. Reopening
   the link shows "Already signed".

**Privacy boundary**: the public API returns only what the signer needs. It does not return the matter number or title,
other documents, internal notes, administrator identities, version history or checksums. The PDF endpoint serves only the
pinned source version, after checking its SHA-256. A signing token gives no access to any administrator endpoint (401).
Errors are generic for unknown, malformed, wrong-purpose and revoked tokens.

## 12. Signature Evidence

`GET …/signing-requests/{id}/evidence` (administrators only), shown in the evidence card:
- **Signer**:
  - designated name, e-mail and type;
  - the confirmed name;
  - identity basis: "Authenticated administrator account (Lexora session)" or "Possession of the secure signing link and
    the name the signer confirmed. No independent identity verification."
- **Consent**: `consent_version` (`2026-11-v1`) and the exact `consent_text` the signer accepted, with
  `consent_accepted_at`.
- **Timestamps**: `signed_at` (server time, also printed in UTC in the PDF caption), plus `sent_at`, `viewed_at` and
  `started_at` on the request.
- **Version checksums**: source and signed version ids and SHA-256 values, the method, the page and the placement.
- **Technical details**: IP address and user agent at completion.
- **Limits**: the evidence shows what happened in this application. It does not prove a real-world identity, and it is not
  a qualified or certificate-based signature. The UI says so on the evidence card and on the signing pages.

## 13. Security Audit

Tested threat scenarios (PHPUnit `SigningSecurityTest`, `SigningRequestTest`, `SigningConcurrencyTest`,
`SigningLifecycleTest`; Playwright scenario G):

| Threat | Result |
| --- | --- |
| Unauthenticated access to every administrator signing endpoint | 401 |
| Request, link, document or version ids from another matter (scoped bindings) | 404 |
| Signing token used on administrator endpoints or for other PDFs | 401 / not found |
| Unknown, malformed, wrong-purpose (upload ↔ signing) and manually revoked tokens | identical 404 `link_invalid`; IP lockout after repeated failures |
| Expired, consumed, cancelled, archived (also after restore) and signed links | 410 with the matching code; cannot sign |
| Signing twice (double click, retry, 4 parallel processes with one link) | exactly one signed version and one record |
| Signing racing revocation, cancellation or archive (multi-process) | either signed or refused, never both or inconsistent |
| Two administrators signing simultaneously | one signed version |
| Spoofed identity (confirmed or typed name ≠ designated signer, admin name in payload) | 422 / ignored (admin name comes from the session) |
| Skipped steps (complete before start, start before view) | 409 |
| Source changed during signing (new version) | refused under lock; nothing stored |
| Oversized or invalid signature payloads, placement outside the page, page out of range | 413 / 422 |
| Token in database, logs, responses or activity | never present (the test scans them) |
| Failure at every pipeline step (engine, storage, DB) | no record, no version, no orphaned file |
| Corrupt signed output | rejected by verification |

**Remaining risks**:
- possession of the link is the only client authentication (anyone the link is forwarded to can sign);
- localhost links only work on the development machine;
- no malware scanning;
- rate limits are per IP;
- the evidence is only as trustworthy as the server and database operator;
- no certificate-based tamper seal (a later edit of the PDF outside the app would not be detectable from the PDF alone,
  but the stored SHA-256 would no longer match).

## 14. Matter Activity

| Event type | Actor | Metadata (no tokens, no signature payloads) |
| --- | --- | --- |
| `signing.requested` | admin | signing_request_id, document_id, source version id/number, signer_type |
| `signing.link_created` | admin | signing_request_id, link_id, expires_at, links_revoked |
| `signing.link_rotated` | admin | same as link_created (links_revoked ≥ 1) |
| `signing.link_revoked` | admin | signing_request_id, link_id, was_expired |
| `signing.invitation_shared` | admin | from ready → to sent, delivery_channel manual, link_id |
| `signing.viewed` | client | from → viewed, source secure_link |
| `signing.started` | client | viewed → started, source secure_link |
| `signing.completed` | admin / client | source and signed version ids and numbers, signed_sha256, signer_type, signature_method, from → signed |
| `signing.cancelled` | admin (manual, or the admin who archived / added a version) | signing_request_id, document_id, source_version_id, from → cancelled, reason, links_revoked |
| `document.signed` | admin / client | same as completed; subject = the signed version |

The timeline shows readable labels and status transitions such as "Started → Signed".

## 15. Frontend

- **Routes**:
  - `/signatures`: global list with search, status and signer filters, sorting and pagination in the URL.
  - Matter tab `?tab=signatures`: counters and list.
  - `/matters/[id]/signatures/[signingRequestId]`: request page.
  - Document page: "Sign PDF" button, open-request note and a "Signed" version badge.
  - `/client-sign`: public page.
  - Dashboard: "Signatures" card with awaiting and signed counts.
  - Sidebar: a Signatures link. No "Coming soon" items are left.
- **Components** (`features/signing/`):
  - `SigningFlow` (page canvas, draggable or keyboard-movable placement box, preview) and `SignatureInput` (pad and typed
    alternative);
  - `AdminSignDialog`, `CreateSigningDialog`, `SigningList`, `SignaturesPanel`, `SignaturesView`, `SigningDetailView`
    (link card, mark-shared confirmation, evidence card);
  - `SigningStatusBadge` and `SigningProgress`;
  - `features/client-sign/client-sign-view.tsx`.
- **Mobile**:
  - single-column layout under 768 px;
  - touch drawing (`touch-action: none` on the pad only) and tap-to-place;
  - full-width controls with no horizontal overflow, verified on a Pixel 7 in E2E.
- **Accessibility**:
  - a labelled WAI-ARIA radio group for the method (one Tab stop; arrow keys select);
  - pad with an `aria-label` and a typed alternative for users who cannot draw;
  - placement box focusable and movable with the arrow keys;
  - errors in `role="alert"`;
  - progress as an ordered list with text states;
  - dialogs with focus management (Radix);
  - consent as a real checkbox, never pre-checked;
  - visible focus rings.

## 16. PHPUnit

Actual result of the full suite in the final Windows verification (section 18): **201 passed, 2,809 assertions,
0 failed, 0 skipped** (83.6 s). An earlier run of the same 201 tests counted 2,810 assertions. One test's assertion count varies by one
between runs; it is data- or timing-dependent, and the exact test was not identified. No test failed or was skipped in
either run. That is the 173 pre-existing Phase 1–4 tests, all still passing, plus 28 new Phase 5
tests. The pre-existing tests were not disabled or weakened.

New Phase 5 tests (28):
- `ClientSigningTest` (4)
  - drawn signature end to end;
  - typed signature;
  - skipped steps, identity changes and invalid signatures refused;
  - landscape, rotated and last pages, with page preservation.
- `SigningRequestTest` (8)
  - exactly one server-determined signer;
  - invalid signers and ineligible sources;
  - every PDF compatibility refusal (form fields, annotations, an existing digital signature, a PDF 1.5
    cross-reference stream, a broken cross-reference), each with its own message and nothing created;
  - client submissions must be reviewed first;
  - links hashed, scoped, expiring, revocable and rotatable;
  - forward-only progress with server timestamps and no e-mail claims;
  - opening before sharing is confirmed;
  - database guards.
- `AdminSigningTest` (3)
  - direct drawn signing;
  - typed signing of a prepared request, with spoofing refused;
  - a client request refused for administrators.
- `SigningLifecycleTest` (6)
  - archiving the matter cancels requests (restore does not revive them);
  - document archive and new versions cancel requests;
  - a source change under the lock is refused;
  - failure injection at every step leaves nothing behind;
  - corrupt output is rejected;
  - regression for the bug in section 20: the temporary name stays reserved while the PDF is generated, and nothing is
    left behind on success or failure. This test was confirmed to fail on the old code.
- `SigningSecurityTest` (4)
  - authentication on every endpoint;
  - cross-matter isolation;
  - public API isolation and rate limiting;
  - tokens never in logs, responses or the database.
- `SigningConcurrencyTest` (3, real parallel PHP processes)
  - 4 simultaneous completions with one link;
  - signing racing revocation, cancellation and archive;
  - two simultaneous administrator signatures.

Existing tests updated, not weakened:
- dashboard summary arrays gained the two signature counters;
- the Phase 4 wrong-purpose test now uses a real signing link.

## 17. Playwright

Actual result of the full suite in the final Windows verification (section 18): **53 passed, 0 failed, 0 flaky,
0 skipped** (10.1 min, one worker). That is the setup step, 45 desktop tests and 7 Pixel 7 tests:
- the 46 tests that passed at the Phase 4 merge, all still passing;
- 7 new Phase 5 tests, numbered 1–5, 7 and 8 below; item 6 is a pre-existing test that was updated, not a new one.

The suite runs on its own servers (API :8011, web :3011) against the disposable `amanet_e2e` database. Earlier
runs of the suite in this phase are listed in section 18.

Phase 5 tests (7 new, plus 1 updated):
1. A client reviews and signs a PDF through a secure link, and the firm verifies the signed version (scenarios A, B, C, F,
   H):
   - request created in the UI; link copied;
   - Ready, then Sent only after confirmation; persists after reload;
   - client isolation; Viewed;
   - drawn signature;
   - download SHA-256 equals the stored checksum; source unchanged;
   - signature pixels visible in the preview; activity labels present.
2. A client signs with a typed signature (scenario D). The output contains Times-Italic and the typed name.
3. The administrator signs an approved client-submitted PDF and the client's original is kept (scenarios E, I):
   - signing refused until review;
   - consent never pre-checked;
   - v1 client upload plus v2 signed;
   - Sign PDF hidden afterwards.
4. Invalid, expired, revoked, signed, cancelled, archived and wrong-purpose links cannot sign (scenario G).
5. Double submission and retries produce exactly one signed version (scenario K).
6. *(updated, not new)* Navigation lists Signatures and no "Coming soon" items (`navigation.spec`).
7. A client signs a PDF on a phone (scenario J, Pixel 7): no overflow, tap placement, consent enforced.
8. The administrator signs with the keyboard alone (scenario L, accessibility):
   - opens the dialog with Enter;
   - the method radio group has a single Tab stop, and the arrow keys select "Type";
   - the placement box is labelled ("Use the arrow keys to move it") and moves 5 % with Shift+Arrow;
   - consent is ticked with Space (not pre-checked) and signing is submitted with Enter;
   - the stored `placement.x` equals where the keyboard left the box.

   This test was verified to fail on the code before the fix in section 20.

Accessibility otherwise relies on role and label queries throughout the suite and on the manual checks. **No automated
accessibility audit (for example axe) was run**: `@axe-core/playwright` is not a project dependency.

## 18. Windows Verification

**Final run.** `verify-windows.bat` on 2026-11-17, 15:03:20, at commit `afd7aa8` (log
`verification-logs/verify-20261117-150320.log`, gitignored and not committed). Environment:
- Windows 11 Pro 10.0.26300, PowerShell 5.1, non-elevated;
- PHP 8.3.35, Composer 2.11.2, Node.js 22.23.5, npm 10.9.9;
- PostgreSQL 16.16 (localhost:5433), Next.js 16.4.6 (Turbopack).

| Step | Result | Duration | Output summary |
| --- | --- | --- | --- |
| PHP `pdo_pgsql` loaded | PASS | — | |
| `composer validate` | PASS | 4 s | `./composer.json is valid` |
| `php artisan migrate` (status) | PASS | 0 s | "Nothing to migrate" (the Phase 5 migration was already applied additively; no reset of `amanet`) |
| `php artisan db:seed` (idempotent) | PASS | 1 s | demo admin + workspace re-seeded without duplicates |
| `php artisan route:list` | PASS | 1 s | 71 routes, including 13 administrator and 5 public signing routes |
| PHPUnit (`php artisan test`) | PASS | 88 s | **Tests: 201 passed (2,809 assertions)** |
| Pint | PASS | 1 s | `{"tool":"pint","result":"passed"}` |
| ESLint (`npm run lint`) | PASS | 9 s | no problems |
| TypeScript (`npm run typecheck`) | PASS | 3 s | `next typegen && tsc --noEmit`, no errors |
| Production build (`npm run build`) | PASS | 11 s | compiled successfully; 18 static pages; routes include `/client-sign`, `/signatures`, `/matters/[id]/signatures/[signingRequestId]` |
| Playwright E2E (Phase 1–5, desktop + mobile) | PASS | 607 s | **53 passed (10.1 min)** |
| **Summary** | **ALL STEPS PASSED** | | 11/11 |

**Runs in this phase.** Every full run is reported, including the failures, which were fixed rather than retried away:

| Run (log) | Code | PHPUnit | Playwright | Result |
| --- | --- | --- | --- | --- |
| `verify-20261117-132709.log` | before the fixes | 199 passed (2,748) | 51 passed, **1 failed**: `phase3-documents.spec.ts:47` timed out at 31.3 s | FAIL. Cause: 30 s test budget under machine load (section 20, "E2E time budget") |
| Playwright only (`npx playwright test`) | + 90 s E2E budget | — | 52 passed (10.2 min); `:47` at 19.6 s | PASS |
| `verify-20261117-135756.log` | + 90 s E2E budget | 197 passed, **2 failed** (`SigningConcurrencyTest`; 2,740 assertions) | 52 passed (10.5 min) | FAIL. Cause: temporary-file race (section 20, "Concurrent signing temp-file race") |
| `verify-20261117-141410.log` | + temp-file fix and regression test | 200 passed (2,762) | 52 passed (11.6 min) | ALL STEPS PASSED |
| `verify-20261117-144057.log` | + compatibility test (`22a9a47`) | 201 passed (2,810) | 52 passed (10.9 min) | ALL STEPS PASSED |
| `verify-20261117-150320.log` | + keyboard radio-group fix and keyboard E2E test (`afd7aa8`) | **201 passed (2,809)** | **53 passed (10.1 min)** | **ALL STEPS PASSED (final)** |

Between runs, the concurrency tests were also run 5 times in a row (15/15 passed), and the failing Phase 3 spec was rerun
alone (5/5 passed).

## 19. Manual Verification

Done on the development stack (`verification-logs/phase5-manual-check-20261117.log`, no secrets) with fictional matters
LEX-2026-0014, 0018, 0019 and 0021. All verification matters were archived afterwards, including 0016, 0017 and 0020,
which were left unsigned by aborted script runs. LEX-2026-0015 was not created by these checks and was left untouched:

- **Administrator, drawn signature**:
  - "Sign PDF" on a 2-page PDF; drew 3 strokes, placed them on page 2, consented and signed.
  - The request page shows Signed, "stored as v2; the original v1 is unchanged" and the evidence card.
  - Preview of v2 shows the strokes and caption on page 2.
  - Download: 200, `application/pdf`, 3,134 B, SHA-256 equal to the stored value.
  - On disk: v1 still hashes to its original value. v2 contains "Electronically signed by Elena Marsh", the UTC time and
    `Ref LX-…`, with 2 pages.
  - The database row and record placement are consistent: a 220 × 79.2 pt rectangle on a 612 × 792 page.
- **Client, typed signature** (LEX-2026-0014, signer "Mira Holt"):
  - Request created in the UI, link copied, marked as shared (Sent).
  - A separate private Chromium context opened the link: no internal data shown; page 2 reviewed; Started.
  - Signing without consent showed the alert; with consent, signing succeeded. Reopening showed "Already signed".
  - Public calls carried the token only in the header: no cookies, no Referer, no query strings, no localStorage.
  - The admin page shows all four progress steps with times, client and typed evidence, and "The link was used to sign".
  - On disk, v1 is unchanged and v2 carries "Mira Holt" and the caption ("Ref LX-56ED4968391F").
  - Activity shows the 7 expected events.
- **Administrator, typed signature**:
  - Done after the full verification, which re-seeds the demo admin, so in a separate Chromium window driven through the
    real UI. The demo password was read from `backend/.env` into memory and never printed.
  - Matter LEX-2026-0018, 3-page "Board Resolution (fictional)", uploaded at 1,157 B with SHA-256 `03013153…`.
  - "Sign PDF" opened on page 3 of 3. Chose Type, entered "Elena Marsh", clicked page 3 ("Signature on page 3 of 3.").
    Consent was **not** pre-checked; ticked it and signed.
  - Request page: Signed; evidence shows Administrator, Typed signature, page 3.
  - v2: `kind = signed`, 3,711 B, 3 pages, SHA-256 `05c6f871…`, identical in the download, the version record and the
    evidence record.
  - The PDF contains `/BaseFont /Times-Italic`, `(Elena Marsh) Tj` and `(Electronically signed by Elena Marsh) Tj`. The
    placement rectangle is 220 × 79.2 pt at (268.94, 577.54).
  - The rendered page 3 shows the cursive name, the rule and the caption ("2026-11-17 13:31:23 UTC · Ref LX-C06DE4AB561B"),
    with 1,175 dark pixels inside the placed box.
  - v1 was downloaded again and still hashes to `03013153…`.
  - "Sign PDF" disappears. Signing v2 through the API returns 422: "This version is already a signed PDF. Signed
    documents cannot be signed again (one signer per PDF)."
- **Client, drawn signature**:
  - Matter LEX-2026-0019, "Consent to Act (fictional)", signer "Noor Haddad".
  - Request created with a link; mark-shared moved it to Sent.
  - An anonymous private context reviewed page 2, started signing, drew two strokes with the mouse, clicked page 2,
    confirmed the name, consented and signed. Reopening the link showed "Already signed"; the context had 0 cookies.
  - Request: Sent, Viewed, Started and Signed timestamps all set; evidence shows drawn, page 2; link state "used".
  - Source `b5a874d9…` unchanged. v2 SHA-256 `c3d76343…`, identical in the download, the version record and the
    evidence record, with 2 pages.
  - The rendered page 2 shows both strokes above the rule and the caption ("Ref LX-64B72BD2741F").
- **Keyboard-only administrator signing** (LEX-2026-0021, after the radio-group fix):
  - opened "Sign PDF" with Enter, and focus moved into the dialog;
  - ArrowRight selected "Type", and the name was typed;
  - the focused placement box, labelled "Signature position on page 1. Use the arrow keys to move it.", moved
    −10 % left and −1 % up with Shift+Left ×2 and Up;
  - consent was ticked with Space and signing submitted with Enter, giving Signed;
  - the stored placement is x 0.4705 and y 0.81, equal to the box position, with method typed.

  The run before the fix failed exactly at the arrow-key method switch, which led to the fix in section 20.
- **Visual inspection of all four signed PDFs**: page images were exported from the app's own PDF.js viewer.
  - administrator drawn: vector strokes;
  - administrator typed: Times-Italic;
  - client typed: Times-Italic;
  - client drawn: vector strokes.

  In each, the signature sits where it was placed, with the "Electronically signed by …" caption, the UTC time and the
  reference below it.
- **Final scans**:
  - All 11 versions of the verification matters were re-hashed on disk; every one equals its stored SHA-256.
  - `signature_records` 5 = signed versions 5 = signed requests 5. One of them, LEX-2026-0003 at 13:34, was a signing
    done by the user in the browser pane and was left untouched.
  - The database holds 64-hex token hashes only. Activity events contain 0 token-like strings, and no temporary
    `lexora-sign-*` files are left behind.
  - There are no PDFs under the public directories and no signing URL or token header in the Laravel logs.
  - Archived verification matters keep their signed requests signed.

## 20. Bugs and Fixes

| Bug | Root cause | Fix and verification |
| --- | --- | --- |
| Phase 3 storage-failure test bypassed after the refactor | `persistUpload` stopped calling the mockable `stage()` | `persistUpload` calls `stage()` again; Phase 3 tests pass |
| Null `strokes`/`typed_name` rejected | `array`/`string` rules without `nullable` | nullable + `required_if`/`prohibited_if` |
| Archived matter reported as a document error | eligibility checked the document first | archive check first in `create` |
| `lock_version` not bumped under frozen time | `touch()` relied on `updated_at` changing | raw increment |
| Wrong user in multi-user tests | the Sanctum guard caches the user | `forgetUser()` between users |
| Failure-injection mocks ignored | controllers resolved once per route | failure tests call `SigningService` directly |
| Windows file locks on generated PDFs | FPDI kept file handles open | `cleanUp(true)` in `finally`; verification parses from memory |
| Restored matter's link said "withdrawn" | the resolver only looked at the matter's current state | uses `cancellation_reason = matter_archived` → "no longer available" |
| E2E label ambiguity ("Your full name") | substring label match | `exact: true` |
| A signed request still showed a disabled "Cancel request" button (manual check) | the button was always rendered | rendered only when `allowed_actions.cancel`; re-checked manually on the next signed request (commit `fc55440`) |
| **Concurrent signing temp-file race** (genuine Phase 5 bug; `SigningConcurrencyTest` failed 2 of 3 tests in `verify-20261117-135756.log` with "signed PDF failed verification (no output / not a valid PDF)"; it had passed in earlier runs) | `SigningService` reserved a unique name with `tempnam()`, **deleted the reservation**, then wrote the PDF to `<name>.pdf`. On Windows, `GetTempFileName` could then give the freed name to a parallel signing process. Both wrote, and one deleted, the same file. The safeguards held: nothing invalid was stored and no original changed. But signing could fail spuriously under concurrent load | The reservation is kept until the attempt ends and removed with the output in `finally` (commit `dd8486e`). A new regression test fails on the old code (checked by temporarily restoring the old line) and passes on the fix. The concurrency tests passed 5/5 times in a row, then in two full verifications |
| **E2E time budget** (`phase3-documents.spec.ts:47` timed out at 31.3 s in `verify-20261117-132709.log`) | Not a code regression. Every assertion that ran passed, and the download returned 200 with the right bytes; the 30 s test timeout hit while the download event was pending. The trace showed every step 1.6–1.8× slower than a clean run, and the first ~30 tests of that run (auth, dashboard, clients) were 1.3–2.7× slower too. Meanwhile CPU-heavy work ran on the same 8-CPU machine (parsing a 23 MB transcript in PowerShell), next to the development servers. The weakness behind it: Phase 1–3 specs used Playwright's 30 s default, while their real-stack workflows take 19–30 s against the single-threaded `php artisan serve` (`:98` and `:159` ran at 29.9 s and 29.5 s) | A suite-wide `timeout: 90_000` in `playwright.config.ts` (commit `f2a15bc`); Phase 4/5 specs keep 180 s. No assertion, expect timeout (15 s) or test step changed. Phase 5 adds no API calls on the document page, and the signature counters exist only on the matter detail endpoint. Rerun alone: 5/5 Phase 3 tests passed (`:47` 20.6 s); then the full suite 52/52 four times (standalone, `135756`, `141410`, `144057`), and 53/53 in the final run (`150320`). Verification now runs with nothing else on the machine |
| Untested compatibility claims (found while finalising this report) | Only the `/Annots` refusal had a test | New test covering form fields, an existing signature, a PDF 1.5 cross-reference stream and a broken cross-reference, each with its specific message (commit `22a9a47`) |
| **Signature-method radio group not keyboard-operable as a radio group** (found while verifying accessibility for this report; keyboard placement had been claimed but was not tested) | The Draw/Type buttons had `role="radio"` but no arrow-key handling and two Tab stops. Each option could still be reached with Tab and activated with Enter/Space, but a screen reader announces a radio group, and its users expect the arrow keys | WAI-ARIA radio pattern: roving `tabIndex` (one Tab stop on the checked option); every arrow key selects and focuses the other option. Re-selecting the current option no longer clears a drawn signature (commit `afd7aa8`). New E2E test 8 (keyboard-only signing) fails on the old code (checked by stashing the fix) and passes now. Also checked manually: a keyboard-only signing stored `x 0.4705, y 0.81`, exactly where the box was moved |

## 21. Git State

- Branch: `feature/phase-5-pdf-signing`, based on `main` `d4fbdbe`.
- Commits on the branch, oldest first (`git log --oneline main..HEAD`):

  | Commit | Subject |
  | --- | --- |
  | `7f8d231` | Phase 5 signing schema, models and secure signing links |
  | `32484ad` | Phase 5 PDF signing engine, signing services and APIs |
  | `706b082` | Phase 5 backend tests: requests, links, progress, client and admin signing, PDF integrity, failures, security, concurrency |
  | `e402617` | Signing: explain archive-cancelled links consistently; serve the consent statement with eligibility |
  | `92c32fc` | Phase 5 frontend: administrator signing, Signatures pages, progress timeline, evidence and the public client signing page |
  | `602dc68` | Phase 5 end-to-end tests: client and admin signing, typed signatures, link restrictions, retries, mobile |
  | `dd8486e` | Signing: keep the temporary output name reserved until the attempt ends |
  | `f2a15bc` | E2E: give every test a 90 s budget on the real stack |
  | `fc55440` | Signing request page: hide "Cancel request" unless it is allowed |
  | `22a9a47` | Signing tests: cover every PDF compatibility refusal |
  | `afd7aa8` | Signature method: implement the WAI-ARIA radio group keyboard pattern (**code verified by the final full run**) |
  | *(next)* | Phase 5 documentation and implementation report: docs only, no code |

- HEAD: the documentation commit on top of `afd7aa8`. Its hash is reported in the final response, because a commit
  cannot contain its own hash. All code at HEAD is identical to `afd7aa8`, the commit verified in section 18.
- Working tree: clean after the documentation commit. Verification logs, `.env` files, private PDFs, test results and
  E2E build output are gitignored, and none were committed.
- Local history note: two of the fix commits were first made with a mixed-up file set, because a quoting error in
  PowerShell left files staged. Both were local and unpushed; they were re-split with `git reset --soft` into the three
  correct commits above. No content was lost, and nothing was ever pushed.
- Merge status: **not merged** into `main` and **not pushed**; both wait for explicit authorisation.

## 22. Phase 6 Readiness

- **Signing invitation e-mails**: `delivery_channel` already allows `email`, and `delivery_status` is separate from signing
  progress. `IssuedLink` returns the one-time URL a mailer can send. "Sent" can then mean a queued and delivered mail
  instead of manual confirmation.
- **Document request e-mails and follow-ups**: Phase 4 links use the same `IssuedLink` / `SecureAccessLinkService`.
  Request `due_at` and the outstanding views give follow-up targets.
- **Notifications**: every status change already produces a typed activity event with structured metadata, ready to fan
  out to notifications.
- **Delivery tracking**: add e-mail outcomes (queued, sent, failed, bounced) to `DeliveryStatus`. Mailpit is already in
  Docker Compose.
- **Signing reminders and expiration notices**: `sent_at`, `viewed_at`, `started_at` and the link `expires_at` give
  scheduling inputs. Lifecycle cancellations record reasons.

Nothing for Phase 6 was implemented.

## 23. Legal and Technical Limitations

- **No qualified digital signature certificate**: no X.509, PAdES or eIDAS qualified or advanced signature, and no trusted
  timestamp. The PDF carries a visible electronic signature and a caption, and the workspace keeps the evidence record.

  **Visible application-level signature vs. certificate-based digital signature**

  | | This implementation | Certificate-based (PAdES / qualified) digital signature |
  | --- | --- | --- |
  | What is in the PDF | Visible drawing operations (strokes or a typed name) plus an "Electronically signed by …" caption | A cryptographic signature (`/ByteRange` + CMS/PKCS#7) over the file bytes, usually with a visible appearance as well |
  | Who vouches for the signer | The workspace: the admin session, or possession of the link plus the confirmed name, recorded in `signature_records` | A certificate issued by a certification authority (for qualified signatures, a qualified trust service provider after identity proofing) |
  | Tamper evidence | Only by comparing the file with the SHA-256 stored in the workspace. The PDF alone proves nothing, and anyone could draw a similar picture | Built in: any change after signing invalidates the signature in any PDF reader |
  | Shown by PDF readers | A drawing on the page; no signature panel and no validity status | A signature panel with the signer, the time and a valid or invalid status |
  | Time evidence | Server clock of the application, recorded in the database and the caption | Optionally an RFC 3161 trusted timestamp |
  | Verifiable without Lexora | No: verification needs the workspace's records | Yes, with the certificate chain |

  Treat the Lexora signature as an application-level electronic signature, a recorded intent to sign, whose evidential
  weight depends on the firm's records and processes. Adding PAdES signing would need a signing certificate (and, for
  qualified signatures, a trust service provider). It was deliberately not attempted, and nothing in the UI or the
  documents claims otherwise.
- **No independent identity verification**: administrators are identified by their authenticated session. Clients are
  identified only by possession of the link and the name they confirm. No ID document, SMS or e-mail OTP.
- **No guaranteed jurisdiction-specific legal compliance**: whether this kind of electronic signature is valid for a given
  document and jurisdiction must be assessed by the firm.
- **Unsupported PDF formats**: compressed cross-reference streams, forms (`/AcroForm`), annotations, existing digital
  signatures and encrypted PDFs are refused (section 5). Typed names outside cp1252 must be drawn.
- **Malware scanning**: carried over from Phases 3 and 4. PDFs are validated structurally but not scanned.
- **Other limitations**:
  - e-mail is not sent (Phase 6);
  - a localhost link only works on the development machine;
  - one signer per PDF by design, so multi-party signing is not supported.

## 24. Final Acceptance Checklist

Evidence key: **U** = PHPUnit (section 16), **E** = Playwright (section 17), **M** = manual check (section 19),
**W** = Windows verification (section 18), **D** = database constraint (section 4).

**Admin signing**

| Criterion | Result | Evidence |
| --- | --- | --- |
| Admin can select an eligible PDF | Passed | M, E3; ineligible versions refused (U `invalid_signers_and_ineligible_sources_are_refused`) |
| Admin can preview the source | Passed | M (dialog renders the PDF with page navigation), E3 |
| Admin can draw a signature | Passed | M, E3, U |
| Admin can type a signature | Passed | U (`…typed_signature_and_identity_cannot_be_spoofed`), M (LEX-2026-0018: typed through the "Sign PDF" dialog; Times-Italic name visible on page 3; SHA-256 verified) |
| Admin can position the signature | Passed | M (click on page 2 → stored `rect_pt`), U placement tests |
| Admin explicitly consents | Passed | E3 (never pre-checked; refused without), U |
| A valid final signed PDF is generated | Passed | M (preview, download, re-parse), U, E3 |
| The original remains unchanged | Passed | M (v1 SHA-256 unchanged on disk), E3, D (immutable versions) |
| Signed version is stored privately | Passed | M (`storage/app/private/documents`, nothing under public dirs) |
| Signer identity and timestamp are recorded | Passed | M (evidence card, `signature_records`), U |

**Client signing**

| Criterion | Result | Evidence |
| --- | --- | --- |
| Secure client signing link can be created | Passed | M, E1 |
| Client can open link without an account | Passed | M (private context, 0 cookies), E1 |
| Client sees only the authorized document | Passed | M, E1, E4, U security tests |
| Client can review the actual PDF | Passed | M (page 2 of 2 reviewed), E1, E7 |
| Client can draw or type a signature | Passed | E1 (drawn), E2 (typed), E7 (drawn on phone); M typed (LEX-2026-0014) and M drawn (LEX-2026-0019) |
| Client can position the signature | Passed | M, E7 (tap), U |
| Client explicitly consents | Passed | M (alert without consent), E7 |
| Client can complete signing | Passed | M, E1, E2, E7 |
| Final signed PDF is generated | Passed | M (v2 with caption), E1, E2 |
| Client cannot sign again through the consumed link | Passed | M ("Already signed"), E4, E5, U concurrency |

**Signing progress**

| Criterion | Result | Evidence |
| --- | --- | --- |
| Draft/Ready correctly represented | Passed | E1, M |
| Sent requires actual sharing confirmation | Passed | E1, M, U (`progress_moves_forward_only…`) |
| Viewed reflects real document access | Passed | E1 (only after rendering), M |
| Started reflects intentional signing activity | Passed | M, U (start requires viewed) |
| Signed occurs only after successful PDF persistence | Passed | U failure injection, D (signed ⇔ signed version) |
| Timestamps are server-generated | Passed | U, D (no client-supplied times accepted) |
| Status transitions cannot regress | Passed | U, D (order CHECK, final-status trigger) |
| Progress persists after reload | Passed | E1 |
| No fake email-sent claims | Passed | U, M ("Lexora does not send it"), section 2 |

**Signed records**

| Criterion | Result | Evidence |
| --- | --- | --- |
| Source version identified | Passed | M, U, D |
| Signed version identified | Passed | M, U, D |
| PDF checksum stored | Passed | M (source and signed SHA-256) |
| Signer information stored | Passed | M |
| Signature method stored | Passed | M (drawn / typed) |
| Consent stored | Passed | M (version + text) |
| Signed timestamp stored | Passed | M |
| Evidence accessible to authorized admin | Passed | M, U (401 without session) |
| Signed PDF downloadable from the corresponding matter | Passed | M, E1 |

**Secure links**

| Criterion | Result | Evidence |
| --- | --- | --- |
| Token cryptographically secure | Passed | 256-bit `random_bytes`, U |
| Token hash stored instead of plaintext | Passed | M (64-char hash only), U |
| Purpose restricted to signing | Passed | E4, U, D |
| Expiry enforced | Passed | E4, U |
| Revocation enforced | Passed | E4, U |
| Rotation works | Passed | U (`signing_links_are_hashed_scoped_expiring_revocable_and_rotatable`) |
| Used link cannot sign twice | Passed | E4, E5, M |
| Wrong-purpose links rejected | Passed | E4 (both directions), U |
| No private information leaked | Passed | M, E1, U |
| Concurrency protection verified | Passed | U `SigningConcurrencyTest` (parallel processes) |

**PDF integrity**

| Criterion | Result | Evidence |
| --- | --- | --- |
| Source PDF preserved | Passed | M, E1, E3 |
| New signed version created | Passed | M, E3 |
| Visible signature embedded | Passed | M (preview), E1 (dark pixels in placed region) |
| Page count and order preserved | Passed | M, U (landscape, rotated and last-page tests) |
| Correct placement | Passed | M (`rect_pt`), E1, U |
| Immutable signed version | Passed | D (trigger) |
| Safe file cleanup | Passed | U failure injection (no orphaned files) |
| Valid preview and download | Passed | M, E1 |
| SHA-256 verification | Passed | M, E1 |

**Activity and UI**

| Criterion | Result | Evidence |
| --- | --- | --- |
| Signing events appear in Matter Activity | Passed | E1, M |
| Admin signing interface works | Passed | M, E3 |
| Client signing interface works | Passed | M, E1, E7 |
| Global Signatures page works | Passed | M |
| Matter Signatures tab works | Passed | M, E1 |
| Status timeline works | Passed | M, E1 |
| Responsive mobile UI works | Passed | E7 (Pixel 7, no horizontal overflow) |
| Accessibility checks pass | Passed (with stated scope) | E8 keyboard-only signing (radio group, labelled placement box moved with the arrow keys, Space and Enter); role/label queries across the suite; manual keyboard-only signing (section 19). A keyboard gap found here was fixed (section 20). **Not covered:** no automated audit (axe) and no screen-reader session |

**Testing and delivery**

| Criterion | Result | Evidence |
| --- | --- | --- |
| All 173 pre-existing PHPUnit tests pass | Passed | final run: 201 passed = 173 pre-existing + 28 new, 0 failed, 0 skipped |
| All 46 pre-existing Playwright tests pass | Passed | final run: 53 passed = 46 pre-existing + 7 new, 0 failed, 0 flaky. `phase3-documents.spec.ts:47` failed once on its time budget and was fixed without touching assertions (section 20) |
| New Phase 5 backend tests pass | Passed | 28/28 (section 16) |
| New Phase 5 E2E tests pass | Passed | 7/7 (section 17) |
| Laravel Pint passes | Passed | `{"tool":"pint","result":"passed"}` |
| ESLint passes | Passed | `npm run lint`, no problems |
| TypeScript passes | Passed | `next typegen && tsc --noEmit`, no errors |
| Production build passes | Passed | `next build`, 18/18 static pages |
| Windows verification passes | Passed | `verify-20261117-150320.log`: ALL STEPS PASSED (11/11) at `afd7aa8` |
| Manual signing tests pass | Passed | admin drawn, admin typed, client typed, client drawn, plus keyboard-only (section 19) |
| Real signed PDF verified | Passed | 4 signed PDFs downloaded, SHA-256 checked against the version and evidence records, re-parsed (pages, fonts, caption), rendered and inspected; originals re-hashed unchanged |
| Git commits created | Passed | section 21 (11 commits plus the documentation commit) |
| Documentation completed | Passed | README, architecture, data model, API conventions, implementation phases, security credentials |
| Phase 5 report created | Passed | this document; no template placeholders remain |

**Overall:** every mandatory criterion passed with direct evidence, and no criterion is marked failed or unverified. Two
stated limits remain: accessibility was verified without an automated axe audit or a screen-reader session, and some
PDF formats are refused by design (section 5). Phase 5 is complete on `feature/phase-5-pdf-signing`; it is **not merged**
into `main` and **not pushed**.
