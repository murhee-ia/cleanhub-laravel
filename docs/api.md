# CleanHub API Reference

The canonical contract for the CleanHub REST API. If a request/response shape is
described here, this document is the source of truth — the frontend and any other
client should map to what is written here rather than re-documenting the wire
format.

> This file grows as the API grows. It currently covers authentication and
> account endpoints; later rounds append new sections (jobs, applications,
> ratings, etc.) in the same format.

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
- All request and response bodies are JSON.

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

- **403 Forbidden** — a signed-link check failed (email verification only).

  ```json
  { "message": "Invalid signature." }
  ```

In local development (`APP_DEBUG=true`), non-validation error responses
(`401`/`403`/`404`/`5xx`) also include `exception`, `file`, `line`, and `trace`
fields for debugging; production returns only `message`. Validation errors
(`422`) are identical in both environments — always just `message` and `errors`.

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
