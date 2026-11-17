# Demo Walkthrough — Lexora Workspace

A 20–30 minute tour of everything the Amanet assignment asks for, using the fictional demo data. Every step is a
real action in the running application; nothing is simulated.

## 0. Start the application

1. Windows: run `setup-windows.bat` once, then `start-windows.bat`. It prints the web and API URLs: by default
   http://localhost:3000 and http://localhost:8000, or the next free ports, e.g. 3010/8010.
2. Start the local mail inbox: `docker compose up -d mailpit`. Its UI is at http://localhost:8025, or the port in
   `LEXORA_MAIL_UI_PORT`. Every e-mail the demo sends lands there; nothing leaves the machine.
3. The demo data comes with setup (`php artisan db:seed`, idempotent). It has:
   - 8 clients, 10 matters, tasks and internal notes;
   - documents with version history;
   - client requests in every state;
   - one administrator signature and one client signature with evidence;
   - one signing request ready to send.
4. The demo account and its password are in the README ("Demo login"). The login page also shows them in development.

> Use any small PDF from your computer for the client-upload steps, or download one from the app first.

## 1. Dashboard

Sign in and look at the **Dashboard**:
- key figures: active matters, clients, deadlines, open tasks;
- the matter pipeline;
- **Client document requests**: awaiting review, waiting for clients, corrections, overdue, approved;
- **Signatures**: awaiting and signed;
- **Client e-mail**: accepted by the mail server, failed;
- recent matters (archived ones are left out) and upcoming deadlines.

Every number is a live database aggregate.

## 2. Clients and matters

1. **Clients** → search "Okafor" → open **Amelia Okafor**: contact details and her matters.
2. Open **LEX-2026-0002 · Wrongful Dismissal Claim**: status, priority, deadline and outstanding work.
3. **Tasks** tab: complete a task, then reopen it.
4. **Internal notes** tab: add a note. Notes are admin-only and never visible through client links.
5. **Activity** tab: dated, attributed history of everything above.
6. Back on **Matters**: search, filters and sorting are kept in the URL. The default view leaves out archived matters;
   **Status → All, including archived** shows them.

## 3. Document library and versions

**Documents**:
- Search by title, file name, matter, client or category.
- Open **Master Services Agreement — carrier draft** (LEX-2026-0001). It has v1 and v2: preview either one in the
  in-app PDF viewer, or download it. Versions are immutable, and each carries its SHA-256 checksum.

## 4. Secure document request, e-mailed to the client

1. **LEX-2026-0002 → Client requests → Termination letter** (waiting for the client, due in 4 days).
2. **Send e-mail**. The dialog shows the recipient (chosen by the server: the matter's client), the link validity, and
   that earlier links stop working.
3. Click **Send e-mail**. The result reads "accepted by the configured mail server". The app is explicit that this is
   not proof of inbox delivery.
4. Open Mailpit, open the message and click **Upload the document securely**. Use a private window or a phone, to see
   it as the client does: no account, no login.
5. Upload a PDF. Back in the workspace:
   - the **bell** shows "Client document received";
   - the request page shows the submission;
   - the **E-mail history** lists the accepted e-mail.

## 5. Review, approval and correction

1. On the same request: **Preview v1** → **Start review** → **Approve** (confirm). The exact approved version is
   recorded, and the PDF is now in the matter's documents.
2. Open **Employment contract and final payslips**, which is already waiting for review. Choose **Request correction**,
   give a reason, and tick **E-mail the correction request to the client**.
3. The client's correction e-mail opens a page that shows the reason. The corrected upload becomes v2 of the same
   document, and v1 is kept.
4. **Client requests** in the sidebar lists every request across matters: status groups, overdue, search.

## 6. Signing invitation and client signature (no account)

1. **LEX-2026-0006 → Signatures → Heads of terms — hotel lease renewal**: Ready to send, signer Marta Ilić.
2. **Send signing invitation** → **Send e-mail**. Progress moves to **Sent** only because the mail server accepted the
   invitation.
3. In Mailpit, click **Review and sign the document**, ideally on a phone. Then:
   - review the actual PDF and click **Start signing**;
   - draw a signature, or switch to **Type**;
   - tap the page to place it;
   - enter the name "Marta Ilic" (accents and case don't matter);
   - tick the consent statement and click **Sign document**.
4. Back in the workspace:
   - the progress shows Sent → Viewed → Started → Signed, with server timestamps;
   - the signed PDF is a **new version** (v2), and the original v1 is unchanged;
   - the **Signature evidence** card shows signer, confirmed name, method, page, both checksums, consent version and
     text, time, IP and user agent;
   - the bell shows "Document signed successfully";
   - the link cannot sign again.
5. **LEX-2026-0004 → Signatures**: a purchase agreement already signed by the client (demo evidence, labelled as
   fictional).

## 7. Administrator signing

**LEX-2026-0007 → Documents → Founders' agreement — draft for review → Sign PDF**:
1. Draw or type a signature.
2. Place it by clicking, dragging, or with the arrow keys.
3. Accept the consent statement and click **Sign document**.

The signed PDF is the next version, with an "Electronically signed by …" caption. LEX-2026-0003 shows an earlier
administrator-signed board resolution.

> These are application-level electronic signatures with an evidence record. They are not qualified or
> certificate-based (PAdES/eIDAS) signatures, and the client's identity is not independently verified.

## 8. Notifications, communication history and audit trail

- **Bell / Notifications**: unread count, mark read, mark all read, links to the request or signature.
- **E-mail history** on request and signing pages: every attempt, with status, time, and the state of the link it
  carried.
- **Activity** on each matter: the full, append-only audit trail, including the e-mail outcomes.

## 9. Follow-ups and reminders

```bash
cd backend
php artisan lexora:communications --dry-run   # what would be sent now
php artisan lexora:communications             # send due reminders
```

- **Document requests:** reminders 3 days before the due date, on the day, and 2 days after; requests without a due
  date get them 4 and 10 days after the link.
- **Signing requests:** 3 days after sharing, and 1 day before the link expires.
- **Limits:** at most 3 per request, 24 h apart, in the firm's time zone; never for completed, cancelled or archived
  work.
- **Automatic runs:** in production, cron runs `schedule:run` every minute. Locally, keep `php artisan schedule:work`
  running.

## 10. Optional: honest failure handling

1. Stop the inbox (`docker compose stop mailpit`) and send any request e-mail. The result reads "E-mail could not be
   sent. No successful delivery has been recorded. The mail server could not be reached." The request and the client's
   existing link are unchanged.
2. Start it again (`docker compose start mailpit`) and click **Try again**. The history shows the failure and the
   accepted retry.
