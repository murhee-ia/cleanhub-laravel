# CleanHub API Reference

The canonical contract for the CleanHub REST API. If a request/response shape
is described here, this document is the source of truth — the frontend and any
other client should map to what is written here rather than re-documenting the
wire format.

---

## Conventions

These apply to **every** endpoint. Individual endpoint entries below do not
repeat them.

### Base URL & versioning

- Local development: `http://localhost:8000/api/v1`
- Every route lives under the `/api/v1` prefix. The version is part of the URL
  path on purpose: a future breaking change ships under a new prefix (`/api/v2`)
  so existing clients keep working untouched.

### Authentication

CleanHub uses **bearer tokens** (Laravel Sanctum personal access tokens).

Think of the token like a wristband you're given at a venue entrance: you prove
who you are once (register or login), receive the wristband, then simply show it
on every later request instead of proving your identity again.

- Send it as a header: `Authorization: Bearer <token>`
- The plaintext token is returned **exactly once**, in the `token` field of the
  register and login responses. It cannot be retrieved again — store it on
  receipt.
- This scheme is **stateless**: there are no cookies and no CSRF tokens. Do not
  send a session cookie or `X-XSRF-TOKEN` header — they are ignored.
- Calling **logout** revokes the token used for that request.

Endpoints are labelled with one of: **Public** (no token), **Bearer token** (a
valid `Authorization: Bearer` header required, else `401`), or **Signed link**
(no token, but the URL must carry a valid signature — used only by the
email-verification link).

### Request & response format

- Send `Content-Type: application/json` and `Accept: application/json` on every
  request. The `Accept` header is what guarantees JSON (not HTML) error bodies.
- Request and response bodies are JSON except file-upload requests, which use
  `multipart/form-data`. Do not set the multipart boundary manually; the HTTP
  client/browser must generate it.

### Standard error shapes

- **422 Unprocessable Content** — validation failed. Returned by any endpoint
  that accepts a body. `errors` maps each rejected field to an array of messages;
  `message` is the first of those messages.

  ```json
  {
    "message": "The email field is required. (and 1 more error)",
    "errors": {
      "email": ["The email field is required."],
      "password": ["The password field is required."]
    }
  }
  ```

- **401 Unauthorized** — a Bearer-token endpoint was called without a valid
  token.

  ```json
  { "message": "Unauthenticated." }
  ```

- **403 Forbidden** — a signed-link check failed (email verification only), or a
  policy denied the action (e.g. a non-owner trying to change a job post, an
  employer calling an apply endpoint).

  ```json
  { "message": "This action is unauthorized." }
  ```

- **404 Not Found** — the referenced record doesn't exist, or exists but is
  scoped away from the caller (e.g. one employer trying to unsave another
  cleaner's saved-job row resolves to a 404, not a 403, so cross-account rows
  are indistinguishable from rows that were never there).

- **409 Conflict** — the request is well-formed and the fields are individually
  valid, but accepting it would clash with something else the caller already
  did. The one endpoint that returns this today is applying to a job whose
  schedule overlaps a job the caller is already accepted for — see
  [Applications](#applications) below. Shaped exactly like a `422`
  (`{ message, errors }`), just with a different status code, so it goes through
  the same field-error handling on the client while still being distinguishable
  from an ordinary validation failure.

  ```json
  {
    "message": "This job's schedule conflicts with a job you're already accepted for.",
    "errors": {
      "cleaning_job_post_id": ["This job's schedule conflicts with a job you're already accepted for."]
    }
  }
  ```

In local development (`APP_DEBUG=true`), non-validation error responses
(`401`/`403`/`404`/`5xx`) also include `exception`, `file`, `line`, and `trace`
fields for debugging; production returns only `message`. Validation errors
(`422`/`409`) are identical in both environments — always just `message` and
`errors`.

### Pagination

Every list endpoint that returns more rows than fit on one screen accepts an
optional `per_page` query parameter and returns Laravel's standard paginator
envelope:

```json
{
  "data": [ /* the rows for this page */ ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": {
    "current_page": 1, "from": 1, "to": 50, "last_page": 3, "total": 120,
    "path": "http://localhost:8000/api/v1/cleaning-job-posts", "per_page": 50,
    "links": [ /* per-page-number pagination links, for building page buttons */ ]
  }
}
```

- `per_page` accepts an integer from **50 to 200**. A page is never smaller than
  50 by design — the UI this API serves always wants enough rows to fill a feed
  or a table without a second round trip for a "load more" click. Anything
  outside that range is a `422` on the `per_page` field, not silently clamped —
  a caller should always know the size it asked for is the size it got.
- Omitting `per_page` defaults to `50`.
- **Two endpoints are the exception**: `GET /cleaning-job-categories` and
  `GET /calendar` return every matching row as a bare JSON array, with no
  `data`/`links`/`meta` wrapper at all and no pagination. Both return a small,
  bounded set the caller always wants in full — the whole category list to
  populate a dropdown, or a whole calendar month's worth of accepted/completed
  jobs — so paginating them would only get in the way of the one request that
  actually needs them.

### Roles

Every user has exactly one role: `cleaner`, `employer`, `moderator`, or `admin`.
Only `cleaner` and `employer` can ever be created through the API (registration);
`moderator` and `admin` are provisioned internally, never self-registered.

### The `user` object

Register and login embed a `user` object with this exact shape (produced by
`UserResource`):

```json
{
  "id": 1,
  "name": "Jane Cleaner",
  "email": "jane@example.com",
  "role": "cleaner",
  "email_verified_at": null
}
```

`email_verified_at` is `null` until the email is verified, after which it is an
ISO-8601 timestamp (e.g. `"2026-07-20T09:15:00.000000Z"`).

### Password policy

Endpoints that set a password (`register`, `reset-password`) apply the app's
default policy via `Password::defaults()`, and always require a matching
`password_confirmation` field:

- **Production:** minimum 12 characters, mixed case, at least one letter, one
  number, one symbol, and not present in a known-breach database.
- **Local / testing:** only `required` and `confirmed` are enforced, so
  development is friction-free.

---

## Endpoints

| Method + Path                                        | Auth required | Purpose                                             |
| ---------------------------------------------------- | ------------- | --------------------------------------------------- |
| `POST /api/v1/auth/register`                         | Public        | Create a cleaner/employer account, return a token.  |
| `POST /api/v1/auth/login`                            | Public        | Exchange credentials for a token.                   |
| `POST /api/v1/auth/logout`                           | Bearer token  | Revoke the token used for this request.             |
| `POST /api/v1/auth/forgot-password`                  | Public        | Email a password-reset link.                        |
| `POST /api/v1/auth/reset-password`                   | Public        | Set a new password using a reset token.             |
| `GET /api/v1/auth/verify-email/{id}/{hash}`          | Signed link   | Mark an account's email verified.                   |
| `POST /api/v1/auth/email/verification-notification`  | Bearer token  | Resend the verification email.                      |
| `GET /api/v1/user`                                   | Bearer token  | Return the authenticated user.                      |
| `GET /api/v1/cleaning-job-categories`                | Public        | List the active cleaning categories.                |
| `GET /api/v1/cleaning-job-posts`                     | Public        | Browse published job posts, filtered/sorted/paginated. |
| `GET /api/v1/cleaning-job-posts/{id}`                | Public        | View one job post.                                  |
| `GET /api/v1/cleaning-job-posts/mine`                | Bearer token  | An employer's own job posts, any status.            |
| `POST /api/v1/cleaning-job-posts`                    | Bearer token  | Post a new job (employer).                          |
| `PATCH /api/v1/cleaning-job-posts/{id}`              | Bearer token  | Edit a draft, or advance a published post's status. |
| `DELETE /api/v1/cleaning-job-posts/{id}`             | Bearer token  | Delete a job post (admin only).                     |
| `GET /api/v1/employers/{id}/cleaning-job-posts`      | Bearer token  | One employer's published job posts, for their public profile. |
| `GET /api/v1/profile`                                | Bearer token  | The authenticated user's own profile.               |
| `PATCH /api/v1/profile`                              | Bearer token  | Update the authenticated user's own profile.        |
| `GET /api/v1/cleaners/{id}`                          | Bearer token  | A cleaner's public profile.                         |
| `GET /api/v1/employers/{id}`                         | Bearer token  | An employer's public profile.                       |
| `GET /api/v1/saved-jobs`                             | Bearer token  | A cleaner's saved jobs.                             |
| `POST /api/v1/saved-jobs`                            | Bearer token  | Save a job (cleaner).                               |
| `DELETE /api/v1/saved-jobs/{jobId}`                  | Bearer token  | Unsave a job (cleaner).                             |
| `GET /api/v1/applications`                           | Bearer token  | A cleaner's own applications, filterable by status. |
| `POST /api/v1/applications`                          | Bearer token  | Apply to a job (cleaner).                           |
| `DELETE /api/v1/applications/{id}`                   | Bearer token  | Withdraw a pending application (cleaner).           |
| `GET /api/v1/cleaning-job-posts/{id}/applications`   | Bearer token  | The applicants of one of an employer's job posts.   |
| `GET /api/v1/applications/{id}/detail`               | Bearer token  | One application, readable by either side of it.     |
| `PATCH /api/v1/applications/{id}/accept`             | Bearer token  | Accept an applicant (employer).                     |
| `PATCH /api/v1/applications/{id}/reject`             | Bearer token  | Reject an applicant (employer).                     |
| `PATCH /api/v1/applications/{id}/note`               | Bearer token  | Set or clear a private note on an applicant (employer). |
| `POST /api/v1/applications/{id}/complete`            | Bearer token  | Mark the cleaner's side complete with proof.          |
| `GET /api/v1/calendar`                               | Bearer token  | A cleaner's accepted/completed jobs.                |
| `POST /api/v1/ratings`                               | Bearer token  | Rate the other party after completing your side.      |
| `GET /api/v1/cleaners/{id}/ratings`                  | Bearer token  | List a cleaner's visible reviews.                     |
| `GET /api/v1/employers/{id}/ratings`                 | Bearer token  | List an employer's visible reviews.                   |
| `GET /api/v1/notifications`                          | Bearer token  | List the caller's notifications.                      |
| `PATCH /api/v1/notifications/read-all`               | Bearer token  | Mark all caller notifications read.                   |
| `PATCH /api/v1/notifications/{id}/read`              | Bearer token  | Mark one caller-owned notification read.              |

### POST /api/v1/auth/register

**Auth:** Public. Creates a `cleaner` or `employer` account and returns an API
token. A verification email is queued as a side effect; the account starts
unverified.

**Request body**

| Field                   | Type   | Required | Rules                                                       |
| ----------------------- | ------ | -------- | ----------------------------------------------------------- |
| `name`                  | string | yes      | max 255 chars                                               |
| `email`                 | string | yes      | valid email, **lowercase**, max 255, unique across users    |
| `password`              | string | yes      | `confirmed`; meets the password policy                      |
| `password_confirmation` | string | yes      | must equal `password`                                       |
| `role`                  | string | yes      | one of `cleaner`, `employer` (moderator/admin rejected)     |

_Sample request body:_

```json
{
  "name": "Jane Cleaner",
  "email": "jane@example.com",
  "password": "Password123!",
  "password_confirmation": "Password123!",
  "role": "cleaner"
}
```

**Success response** — `201 Created`

```json
{
  "token": "1|3s9Kk2Xf7pQwVv1aB0cDeFgHiJkLmNoPqRsTuVwX",
  "user": {
    "id": 1,
    "name": "Jane Cleaner",
    "email": "jane@example.com",
    "role": "cleaner",
    "email_verified_at": null
  }
}
```

**Error responses**

- `422` — any field invalid, e.g. duplicate `email`, non-lowercase `email`,
  `role` of `moderator`/`admin`, or `password`/`password_confirmation` mismatch.

  ```json
  {
    "message": "The email has already been taken.",
    "errors": { "email": ["The email has already been taken."] }
  }
  ```

**Notes**

- A fresh account is always unverified (`email_verified_at: null`).
- `email` must be sent lowercase — `"Jane@Example.com"` is rejected on the
  `email` field. Lowercase it client-side before sending.

### POST /api/v1/auth/login

**Auth:** Public. Exchanges credentials for an API token.

**Request body**

| Field      | Type   | Required | Rules       |
| ---------- | ------ | -------- | ----------- |
| `email`    | string | yes      | valid email |
| `password` | string | yes      | —           |

_Sample request body:_

```json
{
  "email": "jane@example.com",
  "password": "Password123!"
}
```

**Success response** — `200 OK` — identical `{ token, user }` shape as register.

**Error responses**

- `422` — missing fields, or wrong credentials:

  ```json
  {
    "message": "These credentials do not match our records.",
    "errors": { "email": ["These credentials do not match our records."] }
  }
  ```

**Notes**

- A wrong email and a wrong password produce the same error on the `email` key —
  deliberate, so the endpoint doesn't reveal which emails have accounts.

### POST /api/v1/auth/logout

**Auth:** Bearer token. Revokes the token used for this request.

**Request body** — none.

**Success response** — `200 OK`

```json
{ "message": "Logged out." }
```

**Error responses**

- `401` — token missing or invalid.

  ```json
  { "message": "Unauthenticated." }
  ```

**Notes**

- Only the current token is revoked; other tokens for the same user stay valid.

### POST /api/v1/auth/forgot-password

**Auth:** Public. Emails a password-reset link.

**Request body**

| Field   | Type   | Required | Rules       |
| ------- | ------ | -------- | ----------- |
| `email` | string | yes      | valid email |

_Sample request body:_

```json
{ "email": "jane@example.com" }
```

**Success response** — `200 OK`

```json
{ "message": "We have emailed your password reset link." }
```

**Error responses**

- `422` — invalid email, unknown email, or throttled:
  - unknown email → `errors.email: ["We can't find a user with that email address."]`
  - throttled → `errors.email: ["Please wait before retrying."]`

  ```json
  {
    "message": "We can't find a user with that email address.",
    "errors": { "email": ["We can't find a user with that email address."] }
  }
  ```

**Notes**

- The emailed link points at the **frontend**, not this API:
  `FRONTEND_URL/reset-password?token=<token>&email=<email>`. The SPA must expose
  that route, read both query params, and submit them to `reset-password`.

### POST /api/v1/auth/reset-password

**Auth:** Public — the `token` from the email is the proof. Sets a new password.

**Request body**

| Field                   | Type   | Required | Rules                                     |
| ----------------------- | ------ | -------- | ----------------------------------------- |
| `token`                 | string | yes      | the token from the reset email            |
| `email`                 | string | yes      | valid email                               |
| `password`              | string | yes      | `confirmed`; meets the password policy    |
| `password_confirmation` | string | yes      | must equal `password`                     |

_Sample request body:_

```json
{
  "token": "9f8c1e0a...reset-token-from-email",
  "email": "jane@example.com",
  "password": "NewPassword123!",
  "password_confirmation": "NewPassword123!"
}
```

**Success response** — `200 OK`

```json
{ "message": "Your password has been reset." }
```

**Error responses**

- `422` — field validation, an invalid/expired token, or unknown email:
  - invalid token → `errors.email: ["This password reset token is invalid."]`

  ```json
  {
    "message": "This password reset token is invalid.",
    "errors": { "email": ["This password reset token is invalid."] }
  }
  ```

### GET /api/v1/auth/verify-email/{id}/{hash}

**Auth:** Signed link. Confirms ownership of the email address and marks the
account verified. This is the link inside the verification email — the user
clicks it, they don't construct it. Laravel appends `?expires=...&signature=...`
when generating the link, and the server rejects any tampered or expired URL.

Path params: `id` (the user id) and `hash` (the SHA-1 of the user's email, baked
into the emailed link).

**Request body** — none.

**Success response** — `302 Found` — redirects to `FRONTEND_URL?verified=1`
(e.g. `http://localhost:5173?verified=1`). The SPA reads `verified=1` to show a
confirmation screen.

**Error responses**

- `403` — URL tampered with or expired.

  ```json
  { "message": "Invalid signature." }
  ```

- `403` — `hash` doesn't match the user's email.

  ```json
  { "message": "Invalid verification link." }
  ```

- `404` — no user with that `id`.

  ```json
  { "message": "No query results for model [App\\Models\\User] 999999" }
  ```

**Notes**

- Idempotent: clicking a still-valid link for an already-verified user still
  redirects with `verified=1` (no error).
- Because the path is under `/api/*`, error responses are JSON even though a
  human opened the link in a browser.

### POST /api/v1/auth/email/verification-notification

**Auth:** Bearer token. Resends the verification email to the authenticated user.

**Request body** — none.

**Success response** — `200 OK`

- `{ "message": "Verification link sent." }` if the user is still unverified.
- `{ "message": "Email already verified." }` if they are already verified.

**Error responses**

- `401` — unauthenticated.

  ```json
  { "message": "Unauthenticated." }
  ```

### GET /api/v1/user

**Auth:** Bearer token. Returns the currently authenticated user as the **raw
model**, which includes `created_at`/`updated_at` — a few more fields than the
trimmed `user` object in the auth responses.

**Request body** — none.

**Success response** — `200 OK`

```json
{
  "id": 1,
  "name": "Jane Cleaner",
  "email": "jane@example.com",
  "role": "cleaner",
  "email_verified_at": null,
  "created_at": "2026-07-20T09:15:00.000000Z",
  "updated_at": "2026-07-20T09:15:00.000000Z"
}
```

**Error responses**

- `401` — unauthenticated.

  ```json
  { "message": "Unauthenticated." }
  ```

**Notes**

- `password` and `remember_token` are never included.

## Cleaning job categories

The fixed list of work types a job post can belong to (residential, hotel,
hospital, office, factory, event cleanup, and so on). An admin manages this
list; every other account only ever reads it.

### GET /api/v1/cleaning-job-categories

**Auth:** Public. Lists every **active** category, alphabetically by name.
Inactive categories are hidden entirely — a job post that already references one
still displays fine (the category is embedded on the post itself), it just can't
be picked for a new post.

**Request body** — none.

**Success response** — `200 OK` — a bare array, not the paginated envelope (see
[Pagination](#pagination)):

```json
[
  { "id": 8, "name": "Event Cleanup", "slug": "event-cleanup" },
  { "id": 5, "name": "Factory", "slug": "factory" },
  { "id": 3, "name": "Hospital", "slug": "hospital" },
  { "id": 2, "name": "Hotel", "slug": "hotel" },
  { "id": 4, "name": "Office", "slug": "office" },
  { "id": 6, "name": "Public Space", "slug": "public-space" },
  { "id": 7, "name": "Research Facility", "slug": "research-facility" },
  { "id": 1, "name": "Residential", "slug": "residential" }
]
```

**Notes**

- `id` is what a job post's `cleaning_job_category_id` field references when
  creating or filtering posts. `slug` exists for the frontend's own use (URLs,
  CSS hooks) and carries no meaning server-side.

## Cleaning job posts

A job post is an employer's listing for cleaning work. Two independent axes
describe where it stands, and they're never merged into one value:

- **`visibility`** — `draft` or `published`. A draft is only visible to the
  employer who owns it; publishing is what makes it appear to cleaners and
  guests at all.
- **`status`** — `open`, `reviewing`, `closed`, `completed`, or `removed`. This
  is the lifecycle of the work itself, and only moves **forward**:
  `open → reviewing → closed → completed`. A post is created `open` and stays
  there until the employer advances it; `removed` is a separate branch reserved
  for a moderator/admin hiding the post, never something an employer sets
  directly, and a post can never move backward or skip a step once published.

A published post's content (title, description, schedule, pay, and so on) is
**locked** the moment it's published — the only field a `PATCH` can still touch
is `status`. Content is only editable while the post is still a `draft`.

### GET /api/v1/cleaning-job-posts

**Auth:** Public, but the response shape depends on who's asking. This is the
main browse/search feed: cleaners and guests scrolling through work, filtering
by category, location, date, or a free-text search term.

**Query parameters**

| Param            | Type    | Notes                                                                 |
| ---------------- | ------- | ---------------------------------------------------------------------- |
| `search`         | string  | Matches against title, description, or the employer's name.          |
| `category_id`    | integer | Must be an existing category id.                                     |
| `country`        | string  | Exact match.                                                          |
| `city`           | string  | Exact match.                                                          |
| `schedule_date`  | date    | Exact match on the post's scheduled date.                            |
| `status`         | string  | **Employer/moderator/admin only** — see below.                       |
| `sort`           | string  | `newest` (default), `soonest` (earliest `schedule_date` first), or `top_employer` (highest visible employer rating first; unrated employers last). |
| `per_page`       | integer | See [Pagination](#pagination).                                       |

**Who sees what:**

- **Guest or cleaner, no `search`** — only `open`, `published` posts. This is
  the passive browse feed.
- **Cleaner, browsing without a search term** — a job the cleaner has already
  applied to drops out of this list entirely. It hasn't disappeared; it's just
  that a passive feed isn't where a cleaner tracks something they've already
  acted on — `GET /applications` is. The moment the cleaner is applying to
  something new, not re-discovering something they've already dealt with, it
  belongs off this list.
- **Cleaner, with a `search` term** — every `published` post regardless of
  status **except `removed`**, including ones the cleaner already applied to.
  Typing a job's name is a deliberate, specific act, so search stays exhaustive
  even where the passive feed narrows.
- **Employer, moderator, or admin** — may additionally pass `status` to filter
  by any status, including `removed`. Sending `status` as a cleaner or guest is
  rejected with a `422` on the `status` field — that filter simply doesn't
  exist for them.

**Success response** — `200 OK`, paginated:

```json
{
  "data": [
    {
      "id": 8,
      "title": "Hotel Housekeeping Team",
      "description": "Looking for a reliable housekeeping team for a 40-room boutique hotel. Daily turnover cleaning, linen changes, and restocking amenities.",
      "requirements": "Must be comfortable with a fast-paced schedule.",
      "qualifications": null,
      "category": { "id": 2, "name": "Hotel" },
      "employer": { "id": 8, "name": "Maria Employer", "rating_average": null, "rating_count": 0 },
      "country": "Philippines",
      "city": "Cebu City",
      "address": null,
      "schedule_date": "2026-09-15",
      "start_time": "08:00",
      "end_time": "16:00",
      "cleaners_needed": 3,
      "application_deadline": null,
      "visibility": "published",
      "status": "open",
      "pay_amount": 1500,
      "pay_currency": "PHP",
      "media": [],
      "is_saved": false,
      "has_applied": false,
      "created_at": "2026-08-07T02:08:16.000000Z",
      "updated_at": "2026-08-07T02:08:16.000000Z"
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "from": 1, "to": 5, "last_page": 1, "total": 5, "per_page": 50 }
}
```

**Field notes**

- `category` is always `{ id, name }` — never a bare id, and no `slug` (that's
  a category-list-only field).
- `employer.rating_average`/`rating_count` are calculated from visible ratings.
  With no visible ratings they are `null`/`0`.
- `is_saved` and `has_applied` only appear for an authenticated **cleaner**
  viewer — a guest or an employer never sees them (there's nothing to attach
  them to). `has_applied: true` also brings along `application_status`, so the
  card doesn't need a second request to know the cleaner is `pending`,
  `accepted`, `rejected`, `withdrawn`, or `completed` on it.
- `applications_count` appears only when the viewer is the post's own employer
  — see [`applications_count` visibility](#applications_count-visibility)
  below.

**Error responses**

- `422` — a cleaner or guest sent `status`:

  ```json
  {
    "message": "Filtering job posts by status is not available for this account.",
    "errors": { "status": ["Filtering job posts by status is not available for this account."] }
  }
  ```

### GET /api/v1/cleaning-job-posts/{id}

**Auth:** Public. A single post's full detail. Any published, non-`removed`
post is visible to anyone; the owning employer can additionally view their own
post in **any** state (including a still-`draft` one, or one that's been
`removed`).

**Success response** — `200 OK` — the same shape as one row of the browse feed
above, plus `applications_count` when the viewer owns the post.

**Error responses**

- `404` — the post doesn't exist, or exists but isn't visible to this viewer
  (a `draft`/`removed` post viewed by anyone but its owner looks identical to a
  post that was never there).

### GET /api/v1/cleaning-job-posts/mine

**Auth:** Bearer token, employer only. The authenticated employer's own posts,
in **every** visibility/status — this is the one place an employer's drafts and
removed posts are listed.

**Query parameters:** `search`, `status`, `schedule_date`, `sort`
(`newest`/`oldest`/`soonest`), `per_page` — all scoped to this employer's own
posts, so `status` here is never rejected the way it is on the public browse
endpoint.

**Success response** — `200 OK`, paginated, each row also carrying
`applications_count` (the employer always owns what they're looking at here).

### POST /api/v1/cleaning-job-posts

**Auth:** Bearer token, employer only.

**Request body**

| Field                       | Type    | Required | Rules                                                  |
| ---------------------------- | ------- | -------- | ------------------------------------------------------- |
| `title`                      | string  | yes      | 101 words or fewer                                     |
| `cleaning_job_category_id`   | integer | yes      | must be an active category                             |
| `description`                | string  | yes      | —                                                       |
| `requirements`               | string  | no       | max 5000 chars                                          |
| `qualifications`             | string  | no       | max 5000 chars                                          |
| `country`                    | string  | yes      | max 255                                                 |
| `city`                       | string  | yes      | max 255                                                 |
| `address`                    | string  | no       | max 255                                                 |
| `schedule_date`              | date    | yes      | today or later                                          |
| `start_time`, `end_time`     | string  | no       | `HH:MM`                                                 |
| `cleaners_needed`            | integer | no       | 1–65535, defaults to 1                                  |
| `application_deadline`       | date    | no       | on or before `schedule_date`                            |
| `visibility`                 | string  | no       | `draft` (default) or `published`                        |
| `pay_amount`                 | number  | no       | 0–99,999,999.99                                         |
| `pay_currency`               | string  | no       | 3-letter code (display only — see root project notes on payment scope) |
| `media`                      | array   | no       | up to 10 image files, 5 MB each                         |

_Sample request body:_

```json
{
  "title": "Hotel Housekeeping Team",
  "cleaning_job_category_id": 2,
  "description": "Looking for a reliable housekeeping team for a 40-room boutique hotel. Daily turnover cleaning, linen changes, and restocking amenities.",
  "requirements": "Must be comfortable with a fast-paced schedule.",
  "country": "Philippines",
  "city": "Cebu City",
  "schedule_date": "2026-09-15",
  "start_time": "08:00",
  "end_time": "16:00",
  "cleaners_needed": 3,
  "pay_amount": 1500,
  "pay_currency": "PHP",
  "visibility": "published"
}
```

**Success response** — `201 Created` — the created post, `status` always
`open` and `applications_count` always `0`.

**Notes**

- Leaving `visibility` unset creates a **draft**, invisible to anyone but the
  owner. This is deliberate — an employer can build a post over several edits
  before it goes live.

### PATCH /api/v1/cleaning-job-posts/{id}

**Auth:** Bearer token, must be the owning employer.

Behaves completely differently depending on whether the post is still a draft:

- **Still a draft** — every field from the create request is editable
  (`sometimes`), plus `visibility` can move from `draft` to `published`.
  `status` cannot be touched here at all (`422` if sent) — a draft is always
  `open` under the hood until it's published.
- **Already published** — every content field is locked (`422` if sent); the
  **only** editable field is `status`, and only forward:
  `open → reviewing → closed → completed`. Sending a status that moves
  backward or targets `removed` (a privileged hide state) is rejected. A post
  may skip forward to a later lifecycle state. Moving to `completed` additionally
  requires a `completion_proof` upload. Completing the post records the
  employer's side only; accepted applications remain `accepted` until their
  cleaners complete them separately.

**Request body (published post):**

```json
{ "status": "reviewing" }
```

To mark a published post completed, send `multipart/form-data` (a browser may
use `POST` plus `_method=PATCH` when necessary):

| Field | Type | Required | Rules |
|---|---|---:|---|
| `status` | string | yes | `completed` |
| `completion_proof` | file | yes | JPG, JPEG, PNG, WEBP, GIF, or PDF; max 10 MB |

The proof is stored on the job post but is not currently exposed by
`CleaningJobPostResource`.

**Success response** — `200 OK` — the updated post.

**Error responses**

- `422` — editing a locked field on a published post:

  ```json
  {
    "message": "A published job post is locked; only its status can be changed.",
    "errors": { "title": ["A published job post is locked; only its status can be changed."] }
  }
  ```

- `422` — an out-of-order status transition:

  ```json
  {
    "message": "A job post status can only move forward: open → reviewing → closed → completed.",
    "errors": { "status": ["A job post status can only move forward: open → reviewing → closed → completed."] }
  }
  ```

- `422` — `status: completed` was sent without a valid proof file; the error is
  returned under `errors.completion_proof`.

### DELETE /api/v1/cleaning-job-posts/{id}

**Auth:** Bearer token, **admin only** — not even the owning employer can
delete their own post. An employer who wants a post gone moves it forward
through `status` instead (`closed`/`completed`); an admin removing a post is a
moderation action, not a routine one.

**Success response** — `200 OK`:

```json
{ "message": "Job post deleted." }
```

**Error responses**

- `403` — anyone other than the admin, including the owning employer:

  ```json
  { "message": "This action is unauthorized." }
  ```

### GET /api/v1/employers/{id}/cleaning-job-posts

**Auth:** Bearer token (any authenticated role). An employer's public job
history for their profile page: every **published**, non-`removed` post
(`open`/`reviewing`/`closed`/`completed`), newest first. Drafts and removed
posts never appear here even to the owning employer viewing their own id — an
employer viewing their **own** history uses `GET /cleaning-job-posts/mine`
instead, which is the only endpoint that also shows `applications_count` and
drafts.

**Success response** — `200 OK`, paginated, same row shape as the browse feed
but without `applications_count` (only `mine` carries that field, since it's
owner-only data and this endpoint is explicitly the one *other* people use to
look at an employer).

## Profiles

Every cleaner and employer has exactly one profile row, created automatically
the first time it's read or written — there's no separate "create profile"
step. The two roles have entirely different field sets; a cleaner's profile and
an employer's profile are never the same shape.

### `applications_count` visibility

Worth calling out once here since it recurs across job-post endpoints:
`applications_count` is only ever present in the JSON when the viewer **is**
the employer who owns that post. Every other viewer — a cleaner browsing, a
guest, another employer looking at someone else's profile — simply doesn't get
the field at all (not `null`, not `0` — absent). This is what keeps how many
people applied to a job private to the poster.

### GET /api/v1/profile

**Auth:** Bearer token, cleaner or employer. Returns the caller's **own**
profile, shaped by their role. The underlying row is created on first access if
it doesn't exist yet, so this never 404s for a valid cleaner/employer account —
a brand-new account just gets an all-`null` profile back.

**Success response — cleaner** — `200 OK`:

```json
{
  "id": 4,
  "user_id": 9,
  "full_name": "Jane Cleaner",
  "email": "jane.cleaner@example.com",
  "photo_url": null,
  "bio": null,
  "country": null,
  "city": null,
  "cleaning_categories": [],
  "languages": [],
  "documents": [],
  "rating_average": null,
  "rating_count": 0,
  "completed_jobs_count": 0,
  "created_at": "2026-08-07T02:09:58.000000Z",
  "updated_at": "2026-08-07T02:09:58.000000Z"
}
```

**Success response — employer** — `200 OK`:

```json
{
  "id": 4,
  "user_id": 8,
  "full_name": "Maria Employer",
  "email": "maria.employer@example.com",
  "employer_type": null,
  "contact_person_name": null,
  "contact_person_contact": null,
  "country": null,
  "city": null,
  "address": null,
  "about": null,
  "photo_url": null,
  "documents": [],
  "rating_average": null,
  "rating_count": 0,
  "posted_jobs_count": 0,
  "completed_jobs_count": 0,
  "created_at": "2026-08-07T02:09:58.000000Z",
  "updated_at": "2026-08-07T02:09:58.000000Z"
}
```

**Notes**

- `email` only appears when the caller is viewing their **own** profile — see
  [`GET /cleaners/{id}` / `GET /employers/{id}`](#get-apiv1cleanersid) below for
  what a third party sees.
- `rating_average` and `rating_count` are calculated from visible ratings.
  `posted_jobs_count` is live. A cleaner's `completed_jobs_count` counts only
  relationships where both the application and job post are completed;
  `EmployerProfileResource.posted_jobs_count` counts only completed job posts.

### PATCH /api/v1/profile

**Auth:** Bearer token, cleaner or employer. Sent as `multipart/form-data`
(via `POST` with `_method=PATCH`, the standard Laravel override) whenever a
`photo` or `documents` file is attached, or plain JSON otherwise. Only the
fields present in the request are changed — this is a true partial update, not
a full replace.

**Cleaner fields**

| Field                 | Type    | Notes                                                          |
| ---------------------- | ------- | --------------------------------------------------------------- |
| `photo`                | file    | image, max 5 MB. Replaces the existing photo.                  |
| `bio`                  | string  | 101 words or fewer                                              |
| `country`, `city`      | string  | max 255 each                                                    |
| `languages`            | array   | array of strings                                                |
| `cleaning_categories`  | array   | array of active category ids — **replaces** the whole set (this one field is a full sync, not a merge, unlike `documents` below) |
| `documents`            | array   | PDF files, max 10 MB each — **appended** to the existing list, never replaces it |

**Employer fields**

| Field                          | Type   | Notes                    |
| -------------------------------- | ------ | -------------------------- |
| `photo`                          | file   | image, max 5 MB           |
| `employer_type`                  | string | max 255                   |
| `contact_person_name`            | string | max 255                   |
| `contact_person_contact`         | string | max 255                   |
| `country`, `city`, `address`     | string | max 255 each               |
| `about`                          | string | max 5000                  |
| `documents`                      | array  | PDF files, max 10 MB each, appended (same as cleaner) |

_Sample request body (cleaner):_

```json
{
  "bio": "Detail-oriented cleaner with hotel and residential experience.",
  "country": "Philippines",
  "city": "Cebu City",
  "cleaning_categories": [1, 2],
  "languages": ["English", "Cebuano"]
}
```

**Success response** — `200 OK` — the full updated profile, same shape as `GET
/profile`:

```json
{
  "id": 4,
  "user_id": 9,
  "full_name": "Jane Cleaner",
  "email": "jane.cleaner@example.com",
  "photo_url": null,
  "bio": "Detail-oriented cleaner with hotel and residential experience.",
  "country": "Philippines",
  "city": "Cebu City",
  "cleaning_categories": [
    { "id": 1, "name": "Residential", "slug": "residential" },
    { "id": 2, "name": "Hotel", "slug": "hotel" }
  ],
  "languages": ["English", "Cebuano"],
  "documents": [],
  "rating_average": null,
  "rating_count": 0,
  "completed_jobs_count": 0,
  "created_at": "2026-08-07T02:09:58.000000Z",
  "updated_at": "2026-08-07T02:10:18.000000Z"
}
```

### GET /api/v1/cleaners/{id}

**Auth:** Bearer token (any authenticated role). A cleaner's public profile —
the same shape as `GET /profile` for a cleaner, minus `email`. A cleaner who
hasn't touched their profile yet still returns a valid (all-empty) object
rather than a `404`.

### GET /api/v1/employers/{id}

**Auth:** Bearer token (any authenticated role). Same idea, for an employer's
public profile.

**Error responses (both)**

- `404` — no user with that id and that role (a cleaner id looked up through
  `/employers/{id}` also 404s — the two endpoints are role-scoped, not just id
  lookups).

## Saved jobs

A cleaner's personal shortlist. Cleaner-only — an employer or moderator gets a
`403` from every endpoint in this section.

### GET /api/v1/saved-jobs

**Auth:** Bearer token, cleaner only. The caller's saved jobs, newest-saved
first, paginated. Each row embeds the **full**, **live** job post — if a saved
job has since closed or filled, that's reflected here immediately, nothing is
filtered out.

**Success response** — `200 OK`, paginated:

```json
{
  "data": [
    {
      "id": 4,
      "saved_at": "2026-08-07T02:08:27.000000Z",
      "job": { "id": 8, "title": "Hotel Housekeeping Team", "status": "open", "...": "full job post" }
    }
  ],
  "links": { "...": "..." },
  "meta": { "...": "..." }
}
```

### POST /api/v1/saved-jobs

**Auth:** Bearer token, cleaner only. Saves a job. The job must be `open` and
`published` **at the moment of saving** — once saved, it stays on the list even
if it later changes status (that's the point of the list: track something even
after it moves on).

**Request body**

```json
{ "cleaning_job_post_id": 8 }
```

**Success response** — `201 Created` — same row shape as one entry of the list
above.

**Error responses**

- `422` — the job isn't currently open/published:

  ```json
  {
    "message": "Only open, published jobs can be saved.",
    "errors": { "cleaning_job_post_id": ["Only open, published jobs can be saved."] }
  }
  ```

- `422` — already saved:

  ```json
  {
    "message": "This job is already in your saved list.",
    "errors": { "cleaning_job_post_id": ["This job is already in your saved list."] }
  }
  ```

### DELETE /api/v1/saved-jobs/{jobId}

**Auth:** Bearer token, cleaner only. Note the route parameter is the **job
post's** id, not the saved-row's id — a job detail page can unsave without
first knowing the id of the underlying saved-jobs row. The lookup is scoped to
the caller's own rows, so trying to unsave a job never in the caller's own
saved list — including one saved by a different cleaner — is a `404`, not a
`403`; there's no way to tell "someone else's row" apart from "no such row" and
there's no need to.

**Success response** — `200 OK`:

```json
{ "message": "Job removed from saved list." }
```

## Applications

An application is what connects one cleaner to one job post. A cleaner can
apply to a job at most once, ever — the underlying table has a permanent
unique constraint on (job post, cleaner), and it is **never deleted**, only
moved through statuses. That's also what makes "withdraw" reversible-looking
but not actually a fresh start: a withdrawn application still occupies that
unique slot, so re-applying to the same job after withdrawing is rejected the
same as any other duplicate.

Status values: `pending` (the only status right after applying) → `accepted` /
`rejected` (an employer's decision). An accepted cleaner can upload proof and
move their own application to `completed`. `withdrawn` is a cleaner-initiated
dead end reachable only from `pending`.

Completion is deliberately independent per side. The cleaner's completion is
stored on the application; the employer's completion is stored on the job post.
Either party may complete first, and completing your own side unlocks your own
rating action without waiting for the other party.

### GET /api/v1/applications

**Auth:** Bearer token, cleaner only. The caller's own applications, newest
first, paginated, optionally narrowed to one status via `?status=`. Each row
embeds the full job post it targets, live status included — an application to
a job that has since closed still shows up here with that reflected.

**Success response** — `200 OK`, paginated:

```json
{
  "data": [
    {
      "id": 5,
      "status": "accepted",
      "message": "I have five years of hotel housekeeping experience and can lead a small team.",
      "resume_url": null,
      "completion_proof_url": null,
      "job": { "id": 8, "title": "Hotel Housekeeping Team", "application_status": "accepted", "has_applied": true, "...": "full job post" },
      "job_completed": false,
      "decision_message": "You are booked in — see you on site at 8am.",
      "created_at": "2026-08-07T02:08:27.000000Z",
      "updated_at": "2026-08-07T02:08:37.000000Z"
    }
  ],
  "links": { "...": "..." },
  "meta": { "...": "..." }
}
```

### POST /api/v1/applications

**Auth:** Bearer token, cleaner only — an employer calling this at all gets a
`403` before any field is even looked at, because the roles are fixed and
mutually exclusive: the person who owns a job post can never simultaneously
hold the cleaner role needed to apply to it. That single role check is the
entire enforcement of "an employer can't apply to their own job" — there's no
separate ownership check anywhere, because there's no code path where it could
ever fire.

Sent as `multipart/form-data` when a `resume` file is attached, plain JSON
otherwise.

**Request body**

| Field                     | Type    | Required | Rules                                  |
| -------------------------- | ------- | -------- | ----------------------------------------- |
| `cleaning_job_post_id`      | integer | yes      | must reference an existing post          |
| `message`                   | string  | no       | 101 words or fewer                       |
| `resume`                    | file    | no       | PDF only, max 10 MB                      |

_Sample request body:_

```json
{
  "cleaning_job_post_id": 8,
  "message": "I have five years of hotel housekeeping experience and can lead a small team."
}
```

**Success response** — `201 Created`:

```json
{
  "id": 5,
  "status": "pending",
  "message": "I have five years of hotel housekeeping experience and can lead a small team.",
  "resume_url": null,
  "completion_proof_url": null,
  "job": { "id": 8, "has_applied": true, "application_status": "pending", "...": "full job post" },
  "job_completed": false,
  "decision_message": null,
  "created_at": "2026-08-07T02:08:27.000000Z",
  "updated_at": "2026-08-07T02:08:27.000000Z"
}
```

Three different rejections can come back from this one endpoint, checked in
this order — each is worth telling apart, since only the last one is a `409`:

**Error responses**

- `422` — the job is no longer open/published (closed, reviewing, completed,
  removed, or still a draft):

  ```json
  {
    "message": "This job is no longer accepting applications.",
    "errors": { "cleaning_job_post_id": ["This job is no longer accepting applications."] }
  }
  ```

- `422` — already applied (including a withdrawn or rejected prior
  application — the unique slot is still occupied):

  ```json
  {
    "message": "You have already applied to this job.",
    "errors": { "cleaning_job_post_id": ["You have already applied to this job."] }
  }
  ```

- `409` — the job's date and time window overlaps a job the cleaner is already
  **accepted** for. A job missing a recorded start/end time is treated as
  occupying its entire scheduled date, since there's no narrower window to
  compare against — two same-day jobs where either one has no time set always
  conflict.

  ```json
  {
    "message": "This job's schedule conflicts with a job you're already accepted for.",
    "errors": { "cleaning_job_post_id": ["This job's schedule conflicts with a job you're already accepted for."] }
  }
  ```

- `403` — the caller is an employer, not a cleaner:

  ```json
  { "message": "This action is unauthorized." }
  ```

### DELETE /api/v1/applications/{id}

**Auth:** Bearer token, must be the applying cleaner, and the application must
still be `pending`. This is a status transition to `withdrawn`, not an actual
row delete — the unique-constraint row has to survive so re-applying to the
same job stays blocked afterward.

**Success response** — `200 OK`:

```json
{ "message": "Application withdrawn." }
```

**Error responses**

- `403` — not the applying cleaner, or the application has already moved past
  `pending` (accepted/rejected/completed can't be withdrawn — the employer has
  already acted).

### GET /api/v1/cleaning-job-posts/{jobId}/applications

**Auth:** Bearer token, must be the employer who owns the job post. The
applicant list for one job post, newest first, paginated. A cleaner who
**withdrew** is dropped from this list — the employer works through active
applicants only — but the total number who withdrew is still surfaced in
`meta.withdrawn_count`, so an employer knows the full picture without seeing
withdrawn rows mixed into their queue.

**Success response** — `200 OK`, paginated, each row shaped for the employer's
side (a `cleaner` summary instead of the cleaner's own `job` embed, plus the
employer-only `private_note`):

```json
{
  "data": [
    {
      "id": 5,
      "status": "pending",
      "message": "I have five years of hotel housekeeping experience and can lead a small team.",
      "resume_url": null,
      "cleaner": {
        "id": 9,
        "full_name": "Jane Cleaner",
        "photo_url": null,
        "rating_average": null,
        "rating_count": 0,
        "completed_jobs_count": 0
      },
      "decision_message": null,
      "private_note": null,
      "created_at": "2026-08-07T02:08:27.000000Z",
      "updated_at": "2026-08-07T02:08:27.000000Z"
    }
  ],
  "links": { "...": "..." },
  "meta": { "current_page": 1, "...": "...", "withdrawn_count": 0 }
}
```

### GET /api/v1/applications/{id}/detail

**Auth:** Bearer token — readable by **both** sides of the application: the
cleaner who submitted it, and the employer who owns the targeted job post. No
one else. The `/detail` suffix exists purely so this single-resource route
doesn't collide with the flat `GET /applications` collection above it.

**Success response** — `200 OK` — shaped per viewer exactly like the list
endpoints above (a cleaner sees `job`, an employer sees `cleaner` +
`private_note`).

### PATCH /api/v1/applications/{id}/accept

**Auth:** Bearer token, must be the employer who owns the targeted job post.
Moves a still-`pending` application to `accepted`. This is the **only** action
in the whole application that ever sets a row to `accepted` — and accepting is
also the only thing that puts a job on the cleaner's calendar (there's no
separate calendar table; `GET /calendar` just reads accepted/completed
applications directly — see [Calendar](#calendar)).

**Request body** — optional:

```json
{ "message": "You are booked in — see you on site at 8am." }
```

**Success response** — `200 OK` — the updated application, `decision_message`
set to whatever was sent (or `null` if omitted). Unlike `private_note` below,
`decision_message` is written **by** the employer **for** the cleaner, so both
sides can read it.

**Error responses**

- `422` — the application isn't `pending` anymore (already decided, or the
  cleaner withdrew it in the meantime):

  ```json
  {
    "message": "This application has already been decided.",
    "errors": { "status": ["This application has already been decided."] }
  }
  ```

### PATCH /api/v1/applications/{id}/reject

**Auth:** Same as accept. Identical shape and the same `422` for an
already-decided application; the only difference is the resulting `status` is
`rejected` instead of `accepted`.

### PATCH /api/v1/applications/{id}/note

**Auth:** Bearer token, must be the owning employer. Sets (or clears) a private
note on the application — visible **only** to the employer who wrote it, never
to the cleaner, unlike `decision_message` above. Can be called at any point in
the application's lifecycle, not just while `pending`.

**Request body** — `note` must be present but may be `null` (that's how a saved
note gets cleared):

```json
{ "note": "Strong resume, follow up about availability for weekends." }
```

**Success response** — `200 OK` — the updated application, `private_note` set.

### POST /api/v1/applications/{id}/complete

**Auth:** Bearer token, must be the cleaner who submitted the application, and
the application must currently be `accepted`. Marks only the cleaner's side of
the relationship complete. It does not change the job post's status.

Send as `multipart/form-data`:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `proof` | file | yes | JPG, JPEG, PNG, WEBP, GIF, or PDF; max 10 MB |

**Success response** — `200 OK` — the application with:

- `status: "completed"`;
- `completion_proof_url` set to the public file URL;
- `job_completed` indicating whether the employer has independently completed
  the job post; and
- `viewer_has_rated: false` until this cleaner submits a rating.

`viewer_has_rated` is conditionally present on application responses only when
the current viewer's rating action is unlocked: for a cleaner, after the
application is completed; for the owning employer, after the job post is
completed. Once that viewer rates, the field becomes `true`.

**Error responses**

- `403` — the caller is not the applying cleaner, or the application is not
  currently `accepted`.
- `422` — the proof is missing or has an unsupported type/size; the validation
  error is under `errors.proof`.

## Calendar

### GET /api/v1/calendar

**Auth:** Bearer token, cleaner only. Every job the caller is currently
`accepted` for, or has `completed`, sorted by schedule date — this is the whole
feed a monthly calendar view needs, fetched in one request. Nothing else
belongs here: `pending`/`rejected`/`withdrawn` applications never show up on a
calendar, because nothing has actually been committed to yet.

**Success response** — `200 OK` — a **bare array**, not the paginated envelope
(see [Pagination](#pagination)) — same row shape as `GET /applications`:

```json
[
  {
    "id": 5,
    "status": "accepted",
    "message": "I have five years of hotel housekeeping experience and can lead a small team.",
    "resume_url": null,
    "completion_proof_url": null,
    "job": {
      "id": 8,
      "title": "Hotel Housekeeping Team",
      "schedule_date": "2026-09-15",
      "start_time": "08:00",
      "end_time": "16:00",
      "country": "Philippines",
      "city": "Cebu City",
      "employer": { "id": 8, "name": "Maria Employer", "rating_average": null, "rating_count": 0 },
      "application_status": "accepted",
      "...": "full job post"
    },
    "job_completed": false,
    "decision_message": "You are booked in — see you on site at 8am.",
    "created_at": "2026-08-07T02:08:27.000000Z",
    "updated_at": "2026-08-07T02:08:37.000000Z"
  }
]
```

**Error responses**

- `403` — the caller isn't a cleaner (an employer's own schedule isn't modeled
  this way — they see their commitments through their job posts' applicant
  lists instead).

## Ratings

Ratings are mutual but independent rows. The API derives the reviewee from the
authenticated party and the application, so clients never submit a
`reviewee_id`. A unique `(application_id, reviewer_id)` constraint allows one
cleaner→employer rating and one employer→cleaner rating for the same
relationship while preventing duplicates in either direction.

Only visible ratings appear in profile review lists or aggregate averages.

### POST /api/v1/ratings

**Auth:** Bearer token, cleaner or employer, and the caller must be one of the
two parties to the application.

Each party unlocks rating by completing their own side:

- cleaner → employer: the application must be `completed`;
- employer → cleaner: the job post must be `completed`.

The other party does not have to complete first.

**Request body**

| Field | Type | Required | Rules |
|---|---|---:|---|
| `application_id` | integer | yes | existing application involving the caller |
| `stars` | integer | yes | 1–5 |
| `text` | string | no | nullable, max 2000 characters |

```json
{
  "application_id": 5,
  "stars": 5,
  "text": "Clear instructions and a professional experience."
}
```

**Success response** — `201 Created`:

```json
{
  "id": 12,
  "application_id": 5,
  "stars": 5,
  "text": "Clear instructions and a professional experience.",
  "reviewer": {
    "id": 9,
    "full_name": "Jane Cleaner",
    "role": "cleaner"
  },
  "reviewee": {
    "id": 8,
    "full_name": "Maria Employer",
    "role": "employer"
  },
  "other_side_completed": false,
  "job_post": {
    "id": 8,
    "title": "Hotel Housekeeping Team",
    "schedule_date": "2026-09-15",
    "city": "Cebu City",
    "country": "Philippines"
  },
  "created_at": "2026-08-14T08:00:00.000000Z"
}
```

`other_side_completed` reports whether the party who received this review has
also completed their side. It provides context only; it does not delay review
creation or visibility.

**Error responses**

- `403` — the caller is not a cleaner/employer party to the application, or
  their own side is not complete.
- `422` — invalid stars/text, or this reviewer already rated this application.
  A duplicate is reported under `errors.application_id`.
- `404` — the application does not exist.

### GET /api/v1/cleaners/{id}/ratings

### GET /api/v1/employers/{id}/ratings

**Auth:** Bearer token. Lists the target user's visible reviews, newest first,
using the standard paginated envelope. `{id}` is the cleaner/employer user id,
not a profile id.

Each `data` row has the same shape as the rating response above. Hidden ratings
are excluded from both this list and the target profile's
`rating_average`/`rating_count`.

**Query parameters**

| Parameter | Type | Rules |
|---|---|---|
| `per_page` | integer | optional, 50–200; defaults to 50 |

**Error responses**

- `404` — the id does not exist with the role named by the route.

## Notifications

The notification API exposes application-lifecycle events through Laravel's
database notification channel. The same notifications are also queued for the
mail channel. Every response is scoped to the authenticated user's own
notification relation; another user's notification id resolves as `404`.

Current application notification types are:

| Type | Recipient | Trigger |
|---|---|---|
| `new_applicant` | employer | a cleaner successfully applies |
| `application_accepted` | cleaner | the employer accepts the application |
| `application_rejected` | cleaner | the employer rejects the application |
| `application_withdrawn` | employer | the cleaner withdraws while pending |
| `job_reminder` | cleaner | an accepted job is scheduled for tomorrow |

The `app:send-job-reminders` command runs daily at `08:00` in the application
timezone. It targets accepted applications scheduled for the next day and
checks the notifications table before sending, so rerunning it does not create
a duplicate reminder for the same application.

### GET /api/v1/notifications

**Auth:** Bearer token. Returns the caller's notifications newest first in the
standard paginated envelope.

**Query parameters**

| Parameter | Type | Rules |
|---|---|---|
| `unread_only` | boolean | optional; accepts `1`, `0`, `true`, or `false` |
| `per_page` | integer | optional, 50–200; defaults to 50 |

**Notification row**

```json
{
  "id": "2f0e87a1-7b93-4f55-9f25-a8e49cc80df0",
  "type": "application_accepted",
  "message": "Your application for \"Hotel Housekeeping Team\" was accepted.",
  "application_id": 5,
  "cleaning_job_post_id": 8,
  "read_at": null,
  "created_at": "2026-08-14T08:00:00.000000Z"
}
```

The resource flattens the stored notification `data` into the top-level object.
Clients route clicks using `type`, `application_id`, and
`cleaning_job_post_id`.

### PATCH /api/v1/notifications/{id}/read

**Auth:** Bearer token. Marks one caller-owned notification read and returns the
updated notification row. Calling it for another user's UUID returns `404`.

### PATCH /api/v1/notifications/read-all

**Auth:** Bearer token. Marks all of the caller's unread notifications read.

**Success response** — `200 OK`:

```json
{ "message": "All notifications marked as read." }
```
