# Phase 6 Implementation Report — E-mail Delivery, Reminders and Administrator Notifications

Branch `feature/phase-6-email-notifications` (from `main` at `d40e602`). After explicit authorisation it was merged into
`main` (merge commit `e19d3b8`) and pushed to the private repository, together with the feature branch.
All clients, matters, documents and addresses used in development and verification are **fictional**.

---

## 1. Executive Summary

**Completed**

- **Real e-mail over SMTP.** Laravel Mail builds MIME messages (HTML plus plain text) and submits them to a configured SMTP
  server. Locally that server is this project's own Mailpit; in production it can be any authenticated SMTP provider,
  configured through environment variables.
- **The e-mails.** Document request, correction request, document reminder, signing invitation and signing reminder
  e-mails, plus a test e-mail to the administrator's own address.
- **Secure link delivery without storing tokens.**
  - E-mails are sent synchronously once the request state has committed.
  - A link is registered (hash only) only after the SMTP server has accepted the message that carries it.
  - No token ever reaches a queue, the database or a log.
  - A failed send leaves the previously delivered link working.
- **Honest delivery tracking.**
  - `email_deliveries` records statuses `sending`, `accepted`, `failed`, `skipped`, safe failure categories, attempt
    counts, idempotency keys and the server's acceptance ID.
  - "Accepted by the mail server" is never presented as inbox delivery.
- **Signing progress.** Ready → Sent happens only when the invitation is accepted by SMTP (`delivery_channel = email`,
  `delivery_status = email_accepted`). Viewed, Started and Signed still come only from the signer, and progress never
  moves backwards.
- **Corrections.** The correction decision can e-mail the client. The decision commits first, and an e-mail failure
  never undoes it.
- **Reminder scheduler** (`php artisan lexora:communications`, every 5 minutes).
  - Schedules: due-date slots (−3, 0, +2 days), a fallback schedule for requests without a due date, and signing slots
    (3 days after sharing, 1 day before link expiry).
  - Limits: per-slot idempotency keys, a 24 h cooldown and a cap of 3 per request.
  - Dates are evaluated in the firm time zone.
  - No reminders after completion, cancellation, archiving or a manual revoke.
- **In-app notifications.** Written in the same transaction as the event (client upload, corrected upload, client
  signature), or once by the scheduler (overdue request, expired signing link, failed or interrupted automatic e-mail).
  The UI has a bell with an unread count, a recent-notifications panel and a `/notifications` page with mark read / mark
  all read, all per administrator.
- **Communication history.** E-mail history cards on request and signing pages, matter-level history, `email.accepted` /
  `email.failed` events in the append-only matter timeline, and dashboard e-mail figures.
- **Tests and verification.**
  - 35 new PHPUnit tests, including real SMTP integration against Mailpit and multi-process races.
  - 6 new Playwright tests that open the delivered e-mail and click its link.
  - Final Windows verification (12 steps): **PHPUnit 236 passed (3,404 assertions), Playwright 59 passed**.
  - Manual checks on the development stack (section 21).

**Remaining limitations** (section 25)

- SMTP acceptance is not inbox delivery. Delivered/bounced statuses would need provider webhooks and are not implemented.
- Exactly-once delivery is impossible across SMTP and PostgreSQL. A process that dies after the server accepted a
  message but before the database recorded it leaves an "interrupted" record (outcome unknown).
- A reminder or resend issues a new link and earlier links for that request stop working. This is by design: the system
  stores hashes only, so it cannot re-send an old link.
- SPF/DKIM/DMARC are not configured or verified. That needs a real sending domain.
- The PDF-signing compatibility limits and electronic-signature legal limits from Phase 5 still apply.

## 2. Original Amanet Requirements Mapping

| Requirement | Phase 6 implementation |
| --- | --- |
| Document requests sent to the client by e-mail | "Send e-mail" on the request page. The server sends to the request's client (`clients.email`) a secure `/client-upload#token` link; the recipient can never be chosen from the payload |
| Signing invitations by e-mail, no client accounts | "Send signing invitation" on the signing page. Recipient is the signer e-mail **snapshot** of the signing request; the link is `/client-sign#token` |
| Follow-ups for outstanding requirements and unsigned requests | Scheduler reminders (section 12) with caps, cooldown and idempotency; stops when the request is completed, cancelled, archived or its link was revoked by an administrator |
| Sent / Viewed / Started / Signed from real events | Sent = SMTP acceptance (or the Phase 5 manual confirmation); Viewed = the PDF actually rendered through the link; Started = "Start signing"; Signed = PDF stored and verified. Queueing, preparing or failing an e-mail never sets Sent |
| Administrator notifications | Database notifications for uploads, corrected uploads, client signatures, overdue requests, expired signing links and failed automatic e-mails; bell, panel and page |
| Activity history | `email.accepted` / `email.failed` events in the append-only `activity_events`, written in the outcome's transaction |

## 3. Architecture

```
Admin UI ──POST …/send-email {idempotency_key}──► CommunicationController
                                                    │
DocumentRequestEmailService / SigningEmailService ──┤ 1 CLAIM (transaction): lock matter → request; replayed key? → return it
                                                    │   eligibility · recipient from the request · cooldown (same type)
                                                    │   one 'sending' per request (partial unique index) → email_deliveries row
                                                    │ 2 SEND (no transaction): token = random_bytes(32) in memory
                                                    │   ClientActionMail (HTML + text) → Laravel Mail → SMTP (MAIL_TIMEOUT)
                                                    │ 3a ACCEPTED (transaction): lock matter → request → delivery
                                                    │   SecureAccessLinkService::registerDeliveredLink (SHA-256 only;
                                                    │   previous usable link revoked 'replaced'; closed request → stored revoked)
                                                    │   signing: Ready → Sent, channel email; delivery accepted; email.accepted
                                                    │ 3b FAILED (transaction): delivery failed + category; email.failed;
                                                    │   (automatic) notification — the request and its links are untouched
Scheduler: schedule:run → lexora:communications every 5 min (withoutOverlapping, onOneServer)
   recover stale 'sending' · overdue / expired-link notifications · reminders → the same services (initiated_by=automatic)
Client actions (upload / signature) ──► existing transactions + AdminNotifier (database notification, same transaction)
Next.js: SendEmailDialog · CommunicationHistory · NotificationBell (30 s unread poll) · /notifications · dashboard card
```

- **SMTP and Laravel Mail.** The default mailer is `smtp`, with `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`,
  `MAIL_PASSWORD` and `MAIL_TIMEOUT` (default 10 s, so a slow server fails fast). Sender: `MAIL_FROM_ADDRESS` /
  `MAIL_FROM_NAME`. Reply-To: `MAIL_REPLY_TO_ADDRESS` / `MAIL_REPLY_TO_NAME`.
- **Queue / outbox.** None, by design (Approach A in the assignment: *synchronous sending after commit*). The tables
  `jobs` and `failed_jobs` stay empty, which the security tests assert. Sending happens in the administrator's request,
  or in the scheduler process for reminders.
- **Scheduler.** Laravel Scheduler (`routes/console.php`) with no external platform. `php artisan schedule:work` in
  development; one cron entry in production (section 6).
- **Notifications.** Laravel's database channel. `User::notifications()` uses a microsecond-precision model so ordering
  is exact, and every query is scoped to the signed-in user.
- **Next.js.** TanStack Query hooks (`features/communications`, `features/notifications`). The API client keeps the
  server's message for the e-mail outcomes only (`502 email_failed`, `503 email_unavailable`); every other 5xx message
  stays generic.

## 4. Database

Migration `2026_11_21_100000_create_communication_tables.php` is additive. It was applied to the development database
with `php artisan migrate`. The development tables were empty when the migration was rolled back once, before any data
existed, to switch the notifications table to microsecond timestamps (section 22).

**`email_deliveries`**

| Column | Notes |
| --- | --- |
| `id`, `uuid` | uuid sent as header `X-Lexora-Delivery` (not a credential) |
| `matter_id`, `client_id` | FK restrict; null only for `test` |
| `document_request_id`, `signing_request_id` | composite FKs `(…, matter_id)` → the request **of that matter** (a delivery can never point at another matter's request) |
| `secure_access_link_id` | FK, unique; the link this e-mail carried (set only when accepted) |
| `communication_type` | `document_request, document_correction, document_request_reminder, signing_invitation, signing_reminder, test` |
| `initiated_by`, `reminder_slot` | `manual` / `automatic`; automatic ⇔ slot set and a reminder type |
| `recipient_email`, `recipient_name`, `subject` | snapshot; CHECK: an `@`, no CR/LF |
| `channel` | `email` |
| `status` | `pending, sending, accepted, failed, cancelled, skipped` |
| `attempt_count`, `last_attempt_at`, `accepted_at`, `failed_at` | CHECKs: accepted ⇔ `accepted_at`; failed ⇔ `failed_at` + category |
| `failure_category` | `connection, timeout, authentication, recipient_rejected, sender_rejected, configuration, invalid_recipient, interrupted, unknown, ineligible, reminder_limit` |
| `provider_message_id` | the ID the SMTP server returned on acceptance ("250 … queued as …") |
| `idempotency_key` | **unique** (manual: `manual:<type>:<id>:<uuid>`; reminders: `reminder:<type>:<id>:<slot>`) |
| `created_by`, timestamps | |

The table has these constraints and indexes:
- partial unique indexes `email_deliveries_one_sending_per_document_request` and `…_per_signing_request`, which allow
  one SMTP submission in progress per request;
- indexes on `(document_request_id, created_at)`, `(signing_request_id, created_at)`, `(matter_id, created_at)` and
  `(status, last_attempt_at)`;
- trigger `email_deliveries_guard`: no DELETE, target and recipient immutable, and a finished outcome is final.

**`notifications`**
- Laravel's standard columns: uuid `id`, `type`, `notifiable` morph, `data` (jsonb) and `read_at`, with timestamps of
  precision 6.
- Indexes on `(notifiable_type, notifiable_id, read_at)` and `created_at`.

**Additive columns**
- `document_requests.overdue_notified_at` and `secure_access_links.expiry_notified_at`, so each of those notifications
  is sent only once.
- `signing_requests.delivery_status` gains `email_accepted`. A new CHECK `signing_requests_delivery_channel_status` ties
  `shared_manually` to the manual channel and `email_accepted` to the email channel.

## 5. Secure Token Delivery

**Problem.** Phase 4/5 tokens are random 256-bit values stored only as SHA-256 hashes. A worker cannot rebuild a token
from its hash.

**Chosen design: synchronous send after commit, with deferred link registration.**

1. Lock the request and claim the send (a short transaction).
2. Generate the token in PHP memory and render it into the message only.
3. Submit the message to the SMTP server, with no transaction open and a timeout.
4. **Only after the server accepted it**, store `SHA-256(token)` as a new link in one transaction. The same
   transaction revokes the previous usable link (`replaced`), updates the request and records the outcome.

**The ten invariants:**
1. **No plaintext token in persistent storage.** Nothing is queued, and the message body is not stored. Tests scan every
   table and the log.
2. **A rolled-back request never produces a usable e-mail.** The claim requires the committed request. If the request
   closes during sending, the link is stored already revoked, so the client sees "request cancelled / unavailable".
3. **A failed e-mail is never reported as sent.** Status `failed`, HTTP 502, Sent not set.
4. **Completed, revoked or superseded requests get no actionable reminder.** Eligibility is checked under the request
   lock, and an administrator's manual revoke stops reminders.
5. **Retries never create two active links.** One claim per request (index), the same idempotency key replays the first
   result, and a link is only registered for an accepted message.
6. **A failed resend never destroys a delivered link.** Nothing changes on failure (tested: the old link keeps working).
7. **Old tokens are never resurrected.** Links are only ever added or revoked, never reactivated.
8. **Revocation takes effect immediately.** Unchanged Phase 4/5 resolvers check the link row on every call.
9. **Purpose checks always apply.** Upload links are registered with purpose `document_upload` and signing links with
   `document_signing`; the public resolvers enforce them (tested in both directions).
10. **Consistency after failures.** SMTP failure leaves only a failed record. Database failure after acceptance makes the
    transaction roll back, and the delivery stays `sending`. A dead worker leaves a `sending` claim, which recovery
    closes as `interrupted` (section 16).

**Rotation semantics.** Every accepted e-mail for a request carries a new link and revokes the previous one, whether it
was e-mailed or copied. The UI warns before sending: "Earlier links for this request stop working once the mail server
accepts it." The e-mail itself says so too. Copy link still works as the manual alternative.

## 6. Email Transport

| Setting | Development (this machine) | Production |
| --- | --- | --- |
| `MAIL_MAILER` | `smtp` | `smtp` |
| `MAIL_HOST` / `MAIL_PORT` | `127.0.0.1` / `1026` (project Mailpit) | provider host / 587 (STARTTLS) or 465 (`MAIL_SCHEME=smtps`) |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | empty | from the hosting secret store — never committed |
| `MAIL_TIMEOUT` | 10 | 10 (lower = faster failure) |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | `workspace@lexora.test` / app name | an address on a domain with SPF/DKIM/DMARC |
| `MAIL_REPLY_TO_ADDRESS` | optional | the firm's client-care mailbox |
| `PUBLIC_APP_URL` | `http://localhost:3010` | **public HTTPS** — sending is refused in production otherwise |
| `LEXORA_EMAIL_ENABLED` | true | true (false = Copy link only) |

**Mailpit.** `docker-compose.yml` defines this project's `mailpit` (development) and `mailpit-e2e` (isolated test
inbox) services.
- Both bind to 127.0.0.1, and their ports are configurable.
- On this machine 1025/8025 belong to another project's Mailpit, so development uses 1026/8026 and E2E uses 1027/8027.
- Start them with `docker compose up -d --pull never mailpit mailpit-e2e` (set `LEXORA_MAIL_SMTP_PORT=1026` and
  `LEXORA_MAIL_UI_PORT=8026` when the defaults are taken).
- The image `axllent/mailpit:v1.31.1` was already present, so nothing was downloaded.
- No other container was started, stopped or modified.

**Readiness.** `GET /api/communications/status` returns `available`, the reason when unavailable, the transport, host,
port, encryption, whether a login is configured, the sender, reply-to and public URL. It never returns the username or
password. Sending is unavailable when:
- e-mail is switched off;
- the mailer is `log` or `array` (outside tests);
- the host or sender is missing;
- in production, the public URL is not HTTPS or is localhost.

The UI then shows the reason and keeps Copy link.

**Production setup, queue and scheduler.** No queue worker is needed. The scheduler needs one cron entry:
`* * * * * php /path/to/backend/artisan schedule:run`. On Windows, use Task Scheduler running `php artisan schedule:run`
every minute; for development, `php artisan schedule:work`. SPF, DKIM and DMARC records for the sending domain are
required for reliable delivery. They are **not** configured or verified here, because that needs a real domain and DNS
access. Full guide: [email-configuration.md](email-configuration.md).

## 7. Email Templates

One responsive layout (`resources/views/mail/client-action.blade.php`, tables and inline styles, 560 px, readable at
320 px) and a plain-text alternative (`client-action-text.blade.php`). The wording for each type is in `MailComposer`.

| Template | Subject | Content |
| --- | --- | --- |
| Document request | `Document request — {firm}` | greeting, requested document, category, due date, the administrator's instructions (escaped), "Upload the document securely", expiry in the firm time zone, "no account needed" |
| Correction request | `Correction requested — {firm}` | the earlier upload is kept; the reason is **not** in the e-mail (it is shown on the secure page) |
| Document reminder | `Reminder: document request — {firm}` | still outstanding / overdue, link |
| Signing invitation | `Signature requested — {firm}` | document, signer, the administrator's message (escaped), "Review and sign the document", expiry, no account |
| Signing reminder | `Reminder: signature requested — {firm}` | still waiting, link |
| Test e-mail | `Test e-mail — {firm}` | no link, no client data |

- **Branding.** Charcoal `#111827`, teal `#0F766E`, off-white `#FAFAF8`.
- **Footer.** Firm name, plus the optional `LEXORA_FIRM_CONTACT_EMAIL` and `LEXORA_FIRM_POSTAL_ADDRESS`; nothing is
  invented.
- **Subjects** never contain matter or document details.
- **Escaping.** HTML escapes every value (verified with `<script>` and `&`). The text part prints raw text, which is
  plain text and cannot execute.
- **Fallback link.** The full URL with its `#` fragment is printed under the button.
- **Headers.** `X-Lexora-Delivery: <uuid>`; reminders also carry `Auto-Submitted: auto-generated`.

## 8. Document Request Emails

1. On the request page, "Send e-mail" opens a dialog showing the client chosen by the server, the sender, the link
   validity and a warning that earlier links stop working.
2. `POST /api/matters/{m}/document-requests/{r}/send-email` sends `{idempotency_key, link_expires_in_days}`. Recipient,
   subject, status and other delivery values are **422 prohibited** in the payload.
3. Possible responses:
   - 201 — accepted;
   - 200 — the same key replayed the earlier result;
   - 502 — failed;
   - 409 — closed, waiting for review, cooldown or already sending;
   - 422 — the client has no valid address;
   - 503 — e-mail unavailable.
4. The client clicks the link and the Phase 4 upload flow follows unchanged. The upload notifies the administrators
   inside its own transaction.

## 9. Signing Invitation Emails

- "Send signing invitation" ("E-mail invitation again" after the first) calls
  `POST /api/matters/{m}/signing-requests/{s}/send-email`.
- **Recipient.** The request's `signer_email` snapshot, which a database trigger keeps immutable. If it is missing the
  response is 422, with a message explaining to cancel the request and recreate it with an address.
- **On acceptance.**
  - A request in **Ready** becomes **Sent** (`sent_at`, `delivery_channel=email`, `delivery_status=email_accepted`).
  - A request already Viewed or Started keeps its status, so there is no regression; only the link is replaced.
  - A request signed or cancelled while the e-mail was being sent gets an already-closed link.
- **Never Sent.** Preparing, failing, or a queued state (there is none) never sets Sent (tested).
- **Completion.** After the signature, the send action disappears and the API refuses with 409 ("already been signed").
  The scheduler only considers Sent, Viewed and Started requests, so no reminders follow (tested in PHPUnit, E2E and
  manually).
- **Manual sharing.** The Phase 5 "Mark invitation as shared" flow is unchanged (`delivery_channel = manual`).

## 10. Correction Emails

- The correction dialog has an option, off by default, "E-mail the correction request to the client". The request
  becomes `request-correction {…, send_email: true}`.
- The review decision and the reason are committed first, with no revealed link. The e-mail then carries a fresh link,
  registered on acceptance.
- If the e-mail fails or is unavailable, the response is 200 with `email.status = failed / not_sent`. The decision
  stands with no half-made link, and the administrator can use "E-mail correction" or generate a link to copy.
- The correction page shows the reason from the submission. The corrected upload becomes the next immutable version,
  and the earlier ones are kept (tested).
- The link's purpose is `document_upload`, scoped to that request only. It grants nothing else (Phase 4 isolation tests
  still pass).

## 11. Delivery Tracking

| Status | Meaning | Set when |
| --- | --- | --- |
| `pending` | prepared, no attempt | (reserved; claims start as `sending`) |
| `sending` | SMTP submission in progress | the claim |
| `accepted` | the SMTP server accepted the message | `250` received; `provider_message_id` = the server's ID |
| `failed` | refused, unreachable, timed out, or **interrupted** (outcome unknown) | the attempt's exception (safe category) or stale-claim recovery |
| `cancelled` | became ineligible before sending | (reserved; eligibility is re-checked under lock in the claim) |
| `skipped` | an automatic reminder slot intentionally not sent | cap reached / no valid address — recorded once per slot |

- There is no `queued` status because nothing is queued.
- There are no `delivered` or `bounced` statuses, because no provider webhook exists to establish them. The UI uses
  "Accepted by the mail server", "Not sent · reason" and "Sending…", never "Delivered".

## 12. Reminder Scheduling

| Item | Rule |
| --- | --- |
| Schedule (requests with a due date) | slots at due date −3, 0 and +2 days (firm time zone); only the **latest** due slot is sent, so a stopped scheduler does not send a burst |
| Schedule (no due date) | 4 and 10 days after the first link was created |
| Signing | 3 days after the invitation was shared; 1 day before the current link expires |
| Eligibility | document: `pending` or `correction_requested`, matter not archived, a link was given before, latest link not revoked by an administrator. Signing: client signer, status `sent`/`viewed`/`started`, a **usable** link (an expired link is not re-issued automatically — the firm is notified instead), signer not active in the last 24 h (a reminder would replace their link) |
| Cooldown | 24 h since the last e-mail of any kind for the request (`LEXORA_REMINDER_MIN_INTERVAL_HOURS`) |
| Maximum | 3 automatic reminders per request (`LEXORA_REMINDER_MAX_PER_REQUEST`); further slots are recorded as `skipped / reminder_limit` |
| Duplicates | key `reminder:<type>:<id>:<status>:<slot>` is unique; `withoutOverlapping`, `onOneServer`; claims under the request lock; tested with 3 parallel scheduler processes |
| Time zone | `LEXORA_TIMEZONE` (`LegalDate`); tested at the New York / UTC day boundary |
| Batch | at most 50 reminders per run (`LEXORA_REMINDER_BATCH_SIZE`) |
| Off switch | `LEXORA_REMINDERS_ENABLED=false`; without a usable transport nothing is attempted |

The same run also:
- closes stale `sending` claims as `interrupted`, with a notification;
- notifies once about overdue requests (`overdue_notified_at`);
- notifies once about expired, unsigned signing links (`expiry_notified_at`).

## 13. Notifications

| Event | Kind | Title | When |
| --- | --- | --- | --- |
| Client upload | `document_submitted` | Client document received — Review required | in the upload transaction |
| Corrected upload | `correction_submitted` | Corrected document received | in the upload transaction |
| Client signature | `document_signed` | Document signed successfully | in the signing transaction (administrators' own signatures do not notify) |
| Overdue request | `request_overdue` | Document request overdue | scheduler, once |
| Expired signing link | `signing_link_expired` | Signing link expired | scheduler, once |
| Failed / interrupted automatic e-mail | `email_failed` | Email delivery failed — Action required | on the failure (manual failures are shown in the dialog instead) |

- **Content.** Data holds the kind, title, short body, internal `href`, matter number/title and the related ids. It never
  contains links, tokens, credentials, notes or document content (tested).
- **Delivery.** Every administrator gets their own record.
- **API.**
  - `GET /api/notifications` (paginated, `unread=1`, with `meta.unread_count`) and `GET /api/notifications/unread-count`.
  - `POST /api/notifications/{id}/read` and `POST /api/notifications/read-all`.
  - All requests are scoped to `$user->notifications()`: another user's id is a 404, guests and link-token holders get
    401, and an `href` that is not a workspace path becomes `/dashboard`.
- **UI.**
  - Header bell with an unread badge, refreshed every 30 s while the tab is visible and on focus. No WebSocket.
  - A recent-notifications panel: a disclosure closed by Escape or an outside click, with focus returned to the bell.
  - A `/notifications` page with All / Unread, mark read, mark all read and pagination.

## 14. Communication History

- **Request level.** `GET …/document-requests/{r}/communications` and `GET …/signing-requests/{s}/communications`
  return the 100 newest records. The "E-mail history" card shows:
  - type, recipient and who sent it (an administrator or "the reminder schedule");
  - time and status line;
  - the link's state and the server ID.
- **Matter level.** `GET /api/matters/{m}/communications` (paginated). Matter activity shows `email.accepted` ("E-mail
  accepted by the mail server") and `email.failed` ("E-mail not sent"). Their metadata holds the delivery id, type,
  `initiated_by`, slot, request ids, recipient snapshot, outcome and link id.
- **Manual vs e-mail sharing** remain distinct: `signing.invitation_shared` (manual) against `email.accepted` plus
  `delivery_channel = email`.
- **Dashboard.** "Client e-mail" shows "Sending now", "Accepted by mail server (30 days)" and "Failed (30 days)", from one
  aggregate query.

## 15. Security Audit

| Threat | Control | Evidence |
| --- | --- | --- |
| SMTP credential exposure | env only; status endpoint omits username/password | `email_that_is_not_configured_is_reported_without_exposing_settings` |
| Header injection | address validator rejects CR/LF, `<>,;"`; names cleaned; DB CHECK on recipient | `client_supplied_text_cannot_inject_headers_or_markup`, `closed_foreign_archived_and_unaddressable…` |
| Arbitrary recipient | recipient fields 422 prohibited; server derives the address | `the_recipient_and_delivery_values_are_decided_by_the_server` |
| Cross-client / cross-matter | scoped bindings + composite FKs to the request's matter | 404 tests (request, history) |
| Token in job payloads | no queue: `jobs` empty | `emailed_tokens_are_stored_nowhere_but_in_the_message` |
| Secrets in logs | errors stored as categories only; log scanned | same test; manual log scan |
| Tracking leakage | no tracking pixels, no third-party analytics; Viewed only from PDF rendering | design + E2E |
| Invalid link regeneration / revoked links by stale jobs | link registered only after acceptance, under lock; closed request → stored revoked; manual revoke stops reminders | `reminders_are_never_sent_for_requests_that_no_longer_need_the_client`, race tests |
| Duplicate reminders / after completion | idempotency keys, cap, cooldown, status checks | scheduler tests, 3-process race |
| Queue replay | no queue; idempotency key replays the first result | idempotency test |
| Forged delivery success | status set only from the transport result; server-owned fields prohibited | failure tests |
| Unauthorized notification access | per-user relation; 404/401 | `read_state_is_per_administrator_persistent_and_private` |
| Untrusted HTML | Blade escaping; text part is plain text | injection test, SMTP integration (escaped `&` / `<scan>`) |
| Incorrect sender identity | sender from configuration only; reply-to configurable | SMTP integration headers |
| Unsafe public host / missing HTTPS | production refuses e-mail unless `PUBLIC_APP_URL` is public HTTPS | `production_refuses_to_email_local_or_plain_http_links` |
| Timezone errors | firm time zone for slots | `the_firm_time_zone_decides_which_day_it_is` |
| Sensitive previews | subjects without matter/document details; correction reason not e-mailed; notification body without content | template tests |
| Abuse | admin-only, per-request cooldown (same type), 20 sends/min per administrator, reminder caps, test e-mail only to the sender | rate-limit test |
| Client tokens on admin endpoints | upload/signing tokens never authorize communication endpoints | `client_links_cannot_reach_communication_management…`, E2E (401) |

**Remaining risks.**
- Bearer links remain the client's only authentication, so anyone the e-mail is forwarded to can act.
- Some mail security scanners pre-fetch links. Pre-fetching reaches only the static page; the fragment token is never
  sent to the server. Viewed is not affected, because the PDF must actually render.
- Recipients' mail providers may rewrite links; see section 25.

## 16. Queue Reliability

There is no queue; the scheduler process and the HTTP request are the "workers". Retries are explicit:
- the administrator's **Try again** uses a new key and makes a new attempt;
- reminders try once per slot. A failed reminder is recorded and notified, and is not retried automatically, which
  prevents loops.

**Crash semantics:**

| Moment of the crash | Result |
| --- | --- |
| Before the SMTP submission | claim stays `sending`; nothing was sent; recovery marks it `failed / interrupted` and notifies; the old link still works |
| During the submission | unknown whether the server took it; the same recovery applies |
| After acceptance, before the finish transaction | the client may have received an e-mail whose link was never registered (it answers "link not valid"), while the previous link still works; recovery reports `interrupted` so the administrator can resend |
| Finish transaction rolled back (database error) | same as above |

- **Why "interrupted" and not "accepted".** The system cannot know whether the message was accepted, and reporting a
  success it cannot prove would be dishonest.
- **Why no automatic resend.** It could duplicate a message that did arrive. True exactly-once delivery across SMTP and
  PostgreSQL is impossible; the design is at-least-once by the administrator's decision, with duplicates prevented
  wherever the system has the knowledge.

**Tested:** duplicate dispatch (idempotency replay), four simultaneous sends, three parallel schedulers, send racing
cancellation, invitation racing the signature, SMTP failure then retry, and stale-claim recovery.

## 17. PHPUnit Results

Final Windows verification (section 20): **236 passed, 3,404 assertions, 0 failed, 0 skipped** (115.7 s). That is the
201 tests of Phases 1–5, all passing, plus **35 new** tests:

| Class | Tests | Covers |
| --- | --- | --- |
| `DocumentRequestEmailTest` | 6 | recipient, content and link, hash-only storage, idempotent replay, cooldown, rotation, server-owned fields, closed/foreign/archived/addressless, real connection failure keeps the old link, unavailable configuration without secrets, failure categories and address validation |
| `SigningInvitationEmailTest` | 4 | Sent on acceptance + e-mailed link completes signing + purpose isolation; failure never Sent; no regression, signer snapshot; admin/cancelled/addressless refused |
| `CorrectionEmailTest` | 3 | e-mailed correction → corrected version + notifications; correction right after the request e-mail (regression); failed correction e-mail keeps the decision, retry |
| `SmtpIntegrationTest` | 2 | **real SMTP to the E2E Mailpit**: headers, both parts, escaping, fragment link from the delivered message completes an upload / opens the signing page; server acceptance ID |
| `ReminderSchedulerTest` | 8 | due-date slots, closed/archived/revoked/never-shared skipped, fallback schedule and cap, cooldown, time-zone boundary, signing reminders (activity, completion), one-time notifications and stale recovery, dry run and switches |
| `NotificationTest` | 3 | creation and safe content, per-administrator read state and isolation, crafted links |
| `CommunicationSecurityTest` | 5 | no token in any table/log, injection, client tokens rejected, rate limit + own-address test e-mail, production HTTPS guard |
| `CommunicationConcurrencyTest` | 4 | multi-process: 4 simultaneous sends, 3 schedulers, send vs cancel (×3), invitation vs signature |

Existing tests updated, none weakened: the dashboard expectations gained the three e-mail counters, and the Phase 4/5
race cleanups remove the notifications their committed fixtures now receive.

## 18. Playwright Results

Final Windows verification: **59 passed, 0 failed, 0 flaky** (18.9 min, one worker). That is the 53 tests of Phases 1–5
plus **6 new**:

1. **(A, D, I) Document request e-mail → upload → notification.**
   - The UI dialog shows the recipient; the message is accepted.
   - The real message is read by its server ID; the test opens the e-mail's HTML in an anonymous browser and clicks
     "Upload the document securely". The link has an empty query string and the token is in the fragment.
   - The upload succeeds, and the client browser gets 401 on `/api/notifications`.
   - The bell count increases; clicking the notification opens the request and marks it read (persisted); the activity
     label shows.
2. **(B, E) Signing invitation.** Sent on acceptance; the client clicks the e-mailed link and signs; all four progress
   steps; history; no further send action; the signature notification opens the evidence.
3. **(C) Correction e-mail.** The reason is not in the e-mail; the correction notice shows on the page; the corrected
   upload is submission 2; notification.
4. **(F) Real SMTP failure.** The test relay refuses connections, and the dialog says "E-mail could not be sent. No
   successful delivery has been recorded. The mail server could not be reached." Status stays Ready to send. After the
   transport is restored, Try again succeeds, and the history shows accepted plus failed.
5. **(G) Scheduler.** Run twice against the disposable E2E database: one reminder, none for the cancelled request, and
   the old link stopped working.
6. **(H) Pixel 7.** Dialog, result, history, bell panel and notifications page all render without horizontal overflow.

Scenario J (regression) is the full suite.

**Test mail infrastructure.**
- The E2E API sends real SMTP through `e2e/smtp-relay.mjs`, a 127.0.0.1-only TCP relay the tests can switch "down", to
  `mailpit-e2e`.
- Global setup empties only that inbox and fails clearly if it is not running.
- No token appears in test step titles: links are clicked, never navigated to by URL.

## 19. Real SMTP Verification

- **PHPUnit `SmtpIntegrationTest`.** Laravel submits over SMTP to `127.0.0.1:1027`. Mailpit's stored message ID equals
  the `provider_message_id` the server returned. From, To and Reply-To, subject, the HTML part (escaped) and the text
  part all carry the same fragment link, whose hash equals the registered link's. Using that token on the public API
  uploads the PDF, or opens the signing info.
- **Playwright.** Every e-mail scenario reads the delivered message from Mailpit by its server ID and **clicks its button
  in a real browser**, then completes the upload or signature.
- **Development stack.** Section 21; nine messages were accepted by the development Mailpit during the check.

## 20. Windows Verification

`verify-windows.bat` on 2026-11-17 at 17:23:24, at commit `f69af6b`. Log: `verification-logs/verify-20261117-172324.log`
(gitignored).

| Step | Result | Duration | Output |
| --- | --- | --- | --- |
| PHP `pdo_pgsql` loaded | PASS | — | |
| E2E Mailpit (isolated SMTP inbox) | PASS | — | reachable at 127.0.0.1:8027 (new step) |
| `composer validate` | PASS | 4 s | valid |
| `php artisan migrate` (status) | PASS | 1 s | nothing to migrate (additive migration already applied; no reset) |
| `php artisan db:seed` | PASS | 1 s | idempotent |
| `php artisan route:list` | PASS | 1 s | **82 routes** (71 + 11 Phase 6) |
| PHPUnit | PASS | 116 s | **Tests: 236 passed (3,404 assertions)** |
| Pint | PASS | 2 s | passed |
| ESLint | PASS | 83 s | no problems |
| TypeScript | PASS | 26 s | no errors |
| Production build | PASS | 30 s | 19 static pages (adds `/notifications`) |
| Playwright E2E (Phase 1–6) | PASS | 1,153 s | **59 passed (18.9 min)** |
| **Summary** | **ALL STEPS PASSED** | | 12/12 |

- **During development:** the full PHPUnit suite also passed at 235 tests (before the cooldown regression test), and the
  Phase 6 Playwright specs were run alone (6/6).
- **Bugs found** in those runs are listed in section 22.
- **Timing.** The Playwright and lint steps ran slower than in Phase 5 (18.9 min against about 10 min). Nothing failed or
  flaked. The slowdown spread across all steps, which points to machine load.

## 21. Manual Verification

Development stack, fictional client "Meridian Harbour Trust (fictional)" and matter LEX-2026-0024. Log:
`verification-logs/phase6-manual-check-20261117.log`. Driven through the real UI by a script; the password stayed in
memory and links were clicked inside the delivered e-mails.

- **Test 1 — request e-mail.**
  - The dialog showed the server-chosen recipient; feedback "accepted by the configured mail server … does not prove it
    reached the inbox".
  - The MIME message had the expected subject and sender and both parts with the fragment link.
  - Clicking the link opened `/client-upload` with an empty query string and no cookies; the upload made the request
    "submitted"; one notification appeared in the bell.
- **Test 3 — correction.**
  - First attempt: the correction was **refused by the cooldown**, which was a bug, reported honestly (section 22).
  - After the fix: the correction e-mail was accepted, its reason was not in the e-mail, the notice showed on the page,
    and the corrected v2 produced a "Corrected document received" notification.
- **Test 2 — signing.**
  - The invitation was accepted, the client clicked the link and typed a signature.
  - Recorded: signed, `email_accepted` with the email channel, all four timestamps.
  - The signed PDF's hash matched the evidence, and the notification was "Document signed successfully".
- **Test 4 — scheduler** on the development database. A dry run first showed nothing due for existing data. For a request
  due in three days: run 1 sent 1 reminder and run 2 sent 0. The slot was `pending:due-3:2026-11-20`, and the signed
  request received 0 reminders.
- **Test 5 — outage.** The project's own development Mailpit container was stopped, giving an honest failure ("The mail
  server could not be reached"). The request stayed pending. After the container was restarted, Try again was accepted.
- **Test 6 — privacy scans.**
  - 0 links and 0 token-like strings in `email_deliveries` (10), `activity_events` (182) and `notifications` (7).
  - 0 jobs and 0 failed jobs; only 64-hex hashes in `secure_access_links`.
  - 0 links in the Laravel log, and nothing password-like in activity.
- **Visual review.** Send dialog, accepted and failed states, e-mail HTML, request and signing pages, notification panel.
- **Cleanup.** LEX-2026-0022 to 0024 were archived.

## 22. Bugs and Fixes

| Bug | Root cause | Fix and verification |
| --- | --- | --- |
| **Correction e-mail blocked by the cooldown** (manual Test 3) | the 60 s double-send cooldown counted any accepted e-mail for the request, so a correction shortly after the request e-mail was refused (honestly reported, decision kept) | cooldown limited to the same communication type (`f69af6b`); regression test fails on the old code (checked) and passes |
| Notification order not exact | `timestampsTz()` stores whole seconds and Laravel's notification model formats seconds, so two notifications in one second tied | `timestamptz(6)` + `WorkspaceNotificationRecord` with microsecond date format; migration re-applied on the development database while its new tables were still empty (verified 0 rows) |
| Notification link not clickable | inline link + screen-reader-only "Unread:" text: the link's box centre fell outside the text, the parent `div` took the click (found by E2E) | link rendered as a block element |
| Leftover notifications from race fixtures | Phase 4/5 race workers commit fixtures; client actions now notify their committed administrator | race cleanups delete those notifications |
| Test-only issues during development | `Mail::fake()` rebinds the mail manager; Sanctum caches the user between requests; "production" re-enables CSRF in tests | test helpers unwrap the real manager, forget guards, check the production guard at service level |
| Stale wording | link cards said links are never e-mailed | wording updated (Copy link as the manual alternative) |

## 23. Git State

- Branch `feature/phase-6-email-notifications`, based on `main` `d40e602`.
- Commits, oldest first:

  | Commit | Subject |
  | --- | --- |
  | `e623fcd` | Phase 6 e-mail transport configuration, communication schema and delivery records |
  | `d7ff173` | Phase 6 e-mail delivery, reminders and administrator notifications |
  | `a1c8759` | Phase 6 backend tests: e-mail delivery, real SMTP, reminders, notifications, security, concurrency |
  | `2f0ed64` | Phase 6 frontend: e-mail actions, delivery history, notification centre |
  | `217b955` | Phase 6 end-to-end tests with real SMTP, and Windows verification of the e-mail inbox |
  | `f69af6b` | E-mail cooldown applies to the same type of message only; link wording (**code verified by the final run**) |
  | `8b02a4a` | Phase 6 documentation and implementation report: docs only |

- **Branch head** `8b02a4a`: all code there equals `f69af6b`.
- **Working tree:** clean after the documentation commit.
- **Not committed (gitignored):** `.env`, logs, Mailpit data (containers have no volume), test results.
- **Merge status:**
  - At report time, the branch was neither merged nor pushed.
  - After the user's explicit authorisation ("Merge and push"), it was merged with `--no-ff` into `main` as `e19d3b8`. The
    merge was clean, because `main` had not moved since `d40e602`.
  - The merged tree is identical to `8b02a4a`, so the final verification at `f69af6b` (code) applies to it unchanged.
  - `main` and `feature/phase-6-email-notifications` were then pushed to the private `origin` without force.
  - The feature branch was kept.

## 24. Phase 7 Readiness

- **Delivery evidence.** Provider webhooks for delivered/bounced (would set statuses only from real events), plus a
  sending domain with SPF/DKIM/DMARC.
- **Operations.** Real cron / Task Scheduler entry, log monitoring of `email.failed`, retention jobs (below), production
  SMTP secrets in a secret store.
- **Security hardening.** Nonce-based CSP, a dependency audit (`npm audit` moderate advisories in dev tooling remain), an
  automated accessibility audit (axe), and a screen-reader pass.
- **Product polish.** A global communications page (the matter-level API exists), notification preferences per
  administrator, reminder settings in the UI.
- **Retention to decide.** Delivery records and activity are audit records (never deleted). Notifications could be
  pruned after N months (not implemented, deliberately non-destructive). No temporary encrypted payloads exist, so there
  is nothing to expire.

## 25. Limitations

- **SMTP acceptance vs inbox delivery.** "Accepted" means the configured SMTP server took the message. Spam filtering,
  bounces and provider delays are invisible without provider webhooks, which are not implemented.
- **Mailbox provider configuration.** SPF, DKIM and DMARC alignment for the sending domain are required in production and
  are **not** configured or verified here.
- **Queue and retry.**
  - No automatic retry of failed e-mails; the administrator resends.
  - An interrupted send is reported as outcome unknown, and a duplicate is possible if the administrator resends a
    message that had in fact arrived.
  - The HTTP request waits for the SMTP server, bounded by `MAIL_TIMEOUT`.
- **Link-based client authentication.**
  - Possession of the e-mailed link is the only client authentication, so a forwarded e-mail can be used by its new
    holder.
  - Each new e-mail replaces the previous link.
  - Some e-mail clients or security gateways may rewrite links; the fragment is preserved by the major clients tested via
    Mailpit, but this cannot be guaranteed for every client. The full URL is printed under the button as a fallback.
- **PDF signing compatibility** (Phase 5): PDF 1.5+ cross-reference streams, forms, annotations, existing digital
  signatures and encrypted PDFs cannot be signed.
- **Electronic signature legal limits** (Phase 5): application-level electronic signatures, not certificate-based or
  qualified, with no independent identity verification.
- **Malware scanning:** none, as in earlier phases.
- **Localhost links:** e-mails sent from the development stack contain `http://localhost` links (fictional development
  messages only). Production refuses to send unless `PUBLIC_APP_URL` is public HTTPS.

## 26. Final Acceptance Checklist

Evidence key:
- **U** = PHPUnit (section 17);
- **E** = Playwright (section 18);
- **M** = manual (section 21);
- **W** = Windows verification (section 20).

**SMTP and email**

| Criterion | Result | Evidence |
| --- | --- | --- |
| SMTP transport works | Passed | U `SmtpIntegrationTest`, E (all e-mail scenarios), M |
| Mailpit/local capture works | Passed | dev Mailpit 1026/8026, E2E Mailpit 1027/8027; W step |
| Production SMTP configuration documented | Passed | section 6, `docs/email-configuration.md` |
| Credentials never committed | Passed | `.env` gitignored; scans; status endpoint test |
| HTML templates work | Passed | U (render), SMTP integration (HTML part), M screenshot |
| Plain-text alternatives work | Passed | SMTP integration (text part, unescaped, same link) |
| Email errors handled | Passed | U failure tests, E (F), M (Test 5) |
| Transport acceptance tracked truthfully | Passed | `provider_message_id` = server ID; "accepted" wording |

**Document requests**

| Criterion | Result | Evidence |
| --- | --- | --- |
| Admin can send a document request email | Passed | E1, M |
| Correct client receives the test message | Passed | U, E1 (To = client), M |
| Email contains the correct secure link | Passed | U (hash = registered link), SMTP integration |
| Link opens the correct public upload page | Passed | E1, M (`/client-upload`, no query) |
| Client uploads PDF without an account | Passed | E1, M (0 cookies) |
| Document appears in the correct matter | Passed | E1, M |
| Administrator receives notification | Passed | U, E1, M |

**Signing invitations**

| Criterion | Result | Evidence |
| --- | --- | --- |
| Admin can send a signing invitation email | Passed | E2, M |
| Intended signer is the recipient | Passed | U (snapshot, later client edit ignored) |
| Email contains the correct signing link | Passed | U, SMTP integration |
| Signer can view the PDF without login | Passed | E2, M |
| Signer can complete signing | Passed | E2, M |
| Signing progress persists | Passed | E2 (reload), M |
| Final signed PDF stored correctly | Passed | M (hash = evidence), Phase 5 tests |
| Completed requests stop reminders | Passed | U, M (0 reminders) |

**Corrections**

| Criterion | Result | Evidence |
| --- | --- | --- |
| Correction email works | Passed | U, E3, M |
| Correction link valid and scoped | Passed | U (purpose, request), Phase 4 isolation tests |
| Corrected upload works | Passed | U, E3, M |
| New version immutable | Passed | U (v1 + v2), Phase 3 immutability |
| Notification created | Passed | U, E3, M |

**Reminders**

| Criterion | Result | Evidence |
| --- | --- | --- |
| Document reminders work | Passed | U, E5, M |
| Signing reminders work | Passed | U |
| Scheduling works | Passed | U, E5, M (Artisan command) |
| Timezone behaviour correct | Passed | U (New York boundary) |
| Cooldown works | Passed | U |
| Reminder limits work | Passed | U (cap → skipped) |
| Duplicate reminders prevented | Passed | U (re-run, 3 processes), E5, M |
| Cancelled requests skipped | Passed | U, E5 |
| Completed requests skipped | Passed | U, M |
| Archived matters skipped | Passed | U |
| Expired/revoked links not e-mailed as usable | Passed | U (revoked → no reminder; expired signing → notification, no re-issue) |

**Notifications**

| Criterion | Result | Evidence |
| --- | --- | --- |
| Notification centre works | Passed | E1, E2, E6 (mobile) |
| Unread count works | Passed | U, E1 |
| Mark read works | Passed | U, E1 |
| Mark all read works | Passed | U |
| Persist after reload | Passed | E1 |
| Deep links work | Passed | E1, E2 |
| Notifications authorized | Passed | U (cross-user 404, guests 401), E1 (client 401) |
| No tokens in notification payloads | Passed | U, M scan |

**Communication history**

| Criterion | Result | Evidence |
| --- | --- | --- |
| Email attempts recorded | Passed | U, E4 (failed + accepted) |
| SMTP acceptance recorded | Passed | U, M |
| Failures recorded | Passed | U, E4, M |
| Reminder events recorded | Passed | U, E5 ("automatic") |
| Manual and email sharing distinguished | Passed | U (`delivery_channel`), Phase 5 manual flow unchanged |
| Audit events append-only | Passed | existing trigger; delivery guard trigger |
| No confidential token leakage | Passed | U scan test, M scans |

**Reliability and security**

| Criterion | Result | Evidence |
| --- | --- | --- |
| No plaintext tokens in database mail metadata | Passed | U, M |
| No plaintext tokens in queue storage | Passed | no queue; `jobs` empty (U, M) |
| No raw tokens in logs | Passed | U, M |
| No SMTP credentials exposed | Passed | U |
| No arbitrary recipient sending | Passed | U |
| No cross-matter emails | Passed | U |
| SMTP failure handling works | Passed | U, E4, M |
| Retry handling works | Passed | U, E4, M |
| Concurrent sends controlled | Passed | U (4 processes) |
| Scheduler duplicate processing controlled | Passed | U (3 processes) |
| No fake Sent status | Passed | U, E4 |
| Existing upload/signing links remain secure | Passed | Phase 4/5 suites unchanged and passing |

**Tests and delivery**

| Criterion | Result | Evidence |
| --- | --- | --- |
| All 201 existing PHPUnit tests pass | Passed | 236 = 201 + 35, 0 failed |
| All 53 existing Playwright tests pass | Passed | 59 = 53 + 6, 0 failed |
| New Phase 6 tests pass | Passed | 35 PHPUnit, 6 Playwright |
| Pint passes | Passed | W |
| ESLint passes | Passed | W |
| TypeScript passes | Passed | W |
| Production build passes | Passed | W |
| Real SMTP integration tested | Passed | U `SmtpIntegrationTest`, E |
| Windows verification passes | Passed | W 12/12 at `f69af6b` |
| Manual end-to-end communication tests pass | Passed | M (after the cooldown fix) |
| Git commits created | Passed | section 23 |
| Documentation accurate | Passed | this report, README, architecture, data model, API conventions, phases, security, e-mail guide |
| No report placeholders | Passed | — |
| Working tree clean | Passed | after the documentation commit |
| Phase 6 report complete | Passed | 26 sections |

**Overall.** Every mandatory criterion passed with direct evidence. The stated limitations (section 25) are scope
boundaries, not failed criteria:
- no inbox-delivery evidence without provider webhooks;
- SPF/DKIM/DMARC not configured;
- bearer-link authentication.

Phase 6 is complete. It was merged into `main` (`e19d3b8`) and pushed after explicit authorisation. Phase 7 has not been
started.
