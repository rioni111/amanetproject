# E-mail configuration (Phase 6)

Lexora sends e-mail with Laravel Mail over **SMTP**, synchronously, while the administrator waits (or inside the scheduler
process for reminders). No queue worker is needed. Every value below lives in `backend/.env` (development) or in the server's
secret store (production). **Never commit SMTP credentials.**

## 1. Development: Mailpit

Mailpit is a local SMTP server and inbox. Nothing sent to it leaves the machine.

```bash
docker compose up -d mailpit mailpit-e2e
```

| Service | SMTP | Web UI | Used by |
| --- | --- | --- | --- |
| `mailpit` | `127.0.0.1:${LEXORA_MAIL_SMTP_PORT:-1025}` | `http://localhost:${LEXORA_MAIL_UI_PORT:-8025}` | the development API (`MAIL_PORT`) |
| `mailpit-e2e` | `127.0.0.1:${LEXORA_E2E_MAIL_SMTP_PORT:-1027}` | `http://localhost:${LEXORA_E2E_MAIL_UI_PORT:-8027}` | Playwright and `SmtpIntegrationTest` only (emptied by the E2E setup) |

**When the ports are already used.** If another project's Mailpit already holds 1025/8025, choose other ports when starting
the containers and point `MAIL_PORT` at them. On the verified machine that was 1026/8026:

```powershell
$env:LEXORA_MAIL_SMTP_PORT = "1026"; $env:LEXORA_MAIL_UI_PORT = "8026"; docker compose up -d mailpit
```

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1026
MAIL_SCHEME=null
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_TIMEOUT=10
MAIL_FROM_ADDRESS="workspace@lexora.test"
LEXORA_MAIL_UI_URL=http://localhost:8026
```

**After editing `.env`.** Restart `php artisan serve` (or run `php artisan config:clear` if the configuration is cached).

## 2. Production: an SMTP provider

| Variable | Value |
| --- | --- |
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | the provider's SMTP host |
| `MAIL_PORT` / `MAIL_SCHEME` | `587` with `MAIL_SCHEME=null` (STARTTLS is negotiated) or `465` with `MAIL_SCHEME=smtps` (implicit TLS) |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | an SMTP-only credential from the provider (secret store) |
| `MAIL_TIMEOUT` | seconds before a slow server counts as a failure (default 10) |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | an address on a domain you control, e.g. `workspace@your-firm.example` |
| `MAIL_REPLY_TO_ADDRESS` / `MAIL_REPLY_TO_NAME` | optional: the mailbox where client replies should go |
| `LEXORA_FIRM_NAME`, `LEXORA_FIRM_CONTACT_EMAIL`, `LEXORA_FIRM_POSTAL_ADDRESS` | shown in the e-mail footer; empty values are omitted |
| `PUBLIC_APP_URL` | the public **HTTPS** address of the web app. In production, e-mail is refused unless it is HTTPS and not localhost |
| `LEXORA_MAIL_UI_URL` | leave empty |

### Deliverability

Publish **SPF**, **DKIM** and **DMARC** records for the sender domain, as instructed by your provider. Without them, messages
may be rejected or filed as spam.

These records are **not** configured or verified by this project: that needs a real domain and DNS access.

### Checking the configuration

1. `GET /api/communications/status` shows whether sending is available, and why not. It never shows the username or
   password.
2. Then, signed in, call `POST /api/communications/test-email`. It sends a message to **your own** address only, at most 3
   times per 10 minutes.

## 3. Scheduler (reminders and one-time notices)

`php artisan lexora:communications` runs every 5 minutes through Laravel's scheduler. Each run:
- closes stale "sending" records as *interrupted*;
- sends the overdue and expired-link notifications, once each;
- sends the reminders that are due.

| Environment | How to run it |
| --- | --- |
| Development | `php artisan schedule:work` (foreground), or run `php artisan lexora:communications` by hand; `--dry-run` only counts |
| Linux server | cron: `* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1` |
| Windows server | Task Scheduler: every minute, `php.exe C:\path\to\backend\artisan schedule:run` |

Several servers can share one database: the schedule uses `withoutOverlapping()` and `onOneServer()`, which need a shared
cache. Each reminder slot also has a unique idempotency key in the database.

### Reminder settings

| Variable | Default | Meaning |
| --- | --- | --- |
| `LEXORA_REMINDERS_ENABLED` | `true` | switch automatic reminders off |
| `LEXORA_REMINDER_MAX_PER_REQUEST` | `3` | automatic reminders per request |
| `LEXORA_REMINDER_MIN_INTERVAL_HOURS` | `24` | minimum time since the last e-mail for the request |
| `LEXORA_REMINDER_BATCH_SIZE` | `50` | reminders per run |
| `LEXORA_TIMEZONE` | `UTC` | the firm's time zone; it decides which calendar day a reminder belongs to |
| `LEXORA_EMAIL_ENABLED` | `true` | `false` disables all e-mail (Copy link still works) |
| `LEXORA_EMAIL_MANUAL_COOLDOWN_SECONDS` | `60` | blocks a repeat of the same e-mail type for one request |
| `LEXORA_EMAIL_SENDS_PER_MINUTE` | `20` | manual sends per administrator per minute |
| `LEXORA_EMAIL_STALE_SENDING_MINUTES` | `10` | after this, an unfinished send is reported as interrupted |

The reminder schedule itself is in `config/lexora.php` → `communications.reminders`:
- document requests with a due date: −3, 0 and +2 days around it;
- document requests without one: 4 and 10 days after the first link;
- signing requests: 3 days after sharing and 1 day before the link expires.

## 4. What the statuses mean

| Shown as | Meaning |
| --- | --- |
| Accepted by the mail server | the configured SMTP server answered "250 OK" and gave a message ID. It does **not** prove the message reached the inbox |
| Sending… | the SMTP conversation is in progress (a few seconds at most) |
| Not sent · reason | the server was unreachable, did not respond in time, rejected the login, sender or recipient, or the send was interrupted. Nothing about the request changed and the earlier link still works |
| Skipped | an automatic reminder slot that was intentionally not sent (maximum reached, no valid address) |

Inbox delivery, bounces and spam filtering are only visible through provider webhooks. Those are not implemented.

## 5. Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| "E-mail unavailable" in the send dialog | Read the reason shown in the dialog: e-mail switched off, mailer `log`/`array`, missing `MAIL_HOST` or `MAIL_FROM_ADDRESS`, or (production) `PUBLIC_APP_URL` not HTTPS |
| "The mail server could not be reached" | Mailpit/SMTP is not running or `MAIL_HOST`/`MAIL_PORT` are wrong: `docker compose ps`, check the port |
| "The mail server did not respond in time" | Network or provider problem; check `MAIL_TIMEOUT`, firewall and the provider's status page |
| "The mail server rejected the configured login" | Wrong `MAIL_USERNAME`/`MAIL_PASSWORD`, or the provider requires an app password or TLS (`MAIL_SCHEME`/port) |
| "The mail server refused the recipient address" | The client's or signer's address is wrong. Correct the client record; a signing request keeps its signer snapshot, so cancel and recreate it |
| "The mail server refused the configured sender address" | `MAIL_FROM_ADDRESS` is not allowed by the provider (verify the sender/domain there) |
| "… accepted by the mail server moments ago. Wait a minute …" (409) | The same e-mail type was sent less than `LEXORA_EMAIL_MANUAL_COOLDOWN_SECONDS` ago |
| Reminders never arrive | Is the scheduler running (`schedule:work` / cron)? Run `php artisan lexora:communications --dry-run` to see what is due; reminders skip closed requests, archived matters and links revoked by an administrator |
| Playwright fails in global setup with a Mailpit message | Start the test inbox: `docker compose up -d mailpit-e2e` |
