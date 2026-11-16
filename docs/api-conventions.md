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
| 404 | Unknown endpoint or record (no model names leaked) | `The requested resource was not found.` |
| 419 | Missing/invalid CSRF token | `CSRF token mismatch.` |
| 422 | Validation failed / wrong credentials | `These credentials do not match our records.` + `errors.email` |
| 429 | Rate limit; includes `Retry-After` header | `Too many login attempts. Please try again in 58 seconds.` |
| 500 | Unexpected error (no stack trace when `APP_DEBUG=false`) | `Server Error` |

**Formats**: timestamps are ISO-8601 with offset in UTC (`2026-11-19T17:00:00+00:00`); enums are lower-case snake strings
(`in_progress`); IDs are integers. Internal fields (password hashes, remember tokens, storage paths) are never serialised.

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

### `GET /api/matters`

| Param | Values |
| --- | --- |
| `search` | ≤ 100 chars; matches title, matter number, matter type, client name |
| `status` | `open`, `in_progress`, `on_hold`, `closed`, `archived`, or `active` (open + in progress + on hold) |
| `priority` | `low`, `medium`, `high`, `urgent` |
| `client_id` | integer |
| `sort` | `updated_at` (default), `opened_at`, `due_at` (nulls last), `matter_number`, `title`, `priority` (by weight) |
| `direction` | `asc` / `desc` (sensible default per sort) |
| `page`, `per_page` | pagination |

Matter object: `id, matter_number, title, description, matter_type, status, priority, is_active, is_overdue, opened_at, due_at,
closed_at, archived_at, updated_at, client {id, name, client_type}, open_tasks_count`.

### `GET /api/clients`

| Param | Values |
| --- | --- |
| `search` | name, email, reference number |
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

## Conventions for new endpoints (Phase 2+)

- Route names `resource.action`, RESTful verbs, plural nouns (`/api/matters/{matter}/tasks`).
- Validation in a dedicated Form Request; authorisation in the request's `authorize()` or `Gate::authorize()` against a policy.
- Output only through API Resources; collections through a `PaginatedCollection` subclass.
- Multi-table writes in `DB::transaction()`, appending an `ActivityLogger` event inside the same transaction.
- Public (client-link) endpoints go under `/api/public/*` with their own rate limiter, token-hash lookup and minimal resources.
