# CleanHub Testing Guide

How to run CleanHub's automated tests, and how to reproduce every tested scenario
by hand.

---

## Setup

Prerequisites:

1. Install dependencies: `composer install`
2. First time only, create the env file and app key:
   `cp .env.example .env && php artisan key:generate`
3. Configure the database. Local development uses **MySQL/MariaDB**
   (`DB_DATABASE=cleanhub`); run `php artisan migrate` to build the schema.
   - The **test suite does not touch your dev database** — `phpunit.xml` points
     tests at an in-memory SQLite database built and torn down per run, so
     running tests is always safe and side-effect free.

Run the whole suite:

```bash
php artisan test
```

Green means every rule documented below still holds.

For the by-hand reproductions, you also need the app running and a way to read
outgoing email:

```bash
php artisan serve                 # serves http://localhost:8000
```

Mail uses the **log** driver in local dev (`MAIL_MAILER=log`), so verification
and password-reset emails — including their links — are written to
`storage/logs/laravel.log` rather than actually sent. `tinker`
(`php artisan tinker`) is used where generating a signed link or token by hand is
simpler than digging through the log.

---

## Manual verification

| Test group        | File                                          | What it protects                                                      |
| ----------------- | --------------------------------------------- | -------------------------------------------------------------------- |
| Registration      | `tests/Feature/Auth/RegistrationTest.php`     | Only self-service roles register; identities are unique; no typo lockout. |
| Login & logout    | `tests/Feature/Auth/AuthenticationTest.php`   | Tokens issue only for valid credentials and are genuinely revocable. |
| Email verification| `tests/Feature/Auth/EmailVerificationTest.php`| Only the real email owner can verify; the link is unforgeable.       |
| Password reset    | `tests/Feature/Auth/PasswordResetTest.php`    | Passwords change only with a genuine broker token.                   |

> Run a whole group with its file path (e.g.
> `php artisan test tests/Feature/Auth/RegistrationTest.php`); narrow to a single
> test with `--filter`, e.g. `php artisan test --filter="rejects a duplicate email"`.

### Registration

**What it verifies & why:** registration is the only public way to create an
account, so it must enforce the core identity rules — a cleaner/employer can
register and starts unverified (happy path + "new accounts must verify"),
`moderator`/`admin` are rejected with no user created (privileged roles are never
self-registered), duplicate emails are rejected (unique identity), and a
mismatched confirmation is rejected (a typo can't silently lock a user out).

**Run just this group:**

```bash
php artisan test tests/Feature/Auth/RegistrationTest.php
```

**Reproduce by hand:**

```bash
# 1. Register a cleaner → expect 201 with { token, user }, user.email_verified_at = null
curl -s -X POST http://localhost:8000/api/v1/auth/register \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name":"Jane Cleaner","email":"jane@example.com","password":"Password123!","password_confirmation":"Password123!","role":"cleaner"}'

# 2. Try to register a moderator → expect 422 with errors.role
curl -s -X POST http://localhost:8000/api/v1/auth/register \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name":"Mod","email":"mod@example.com","password":"Password123!","password_confirmation":"Password123!","role":"moderator"}'

# 3. Re-register jane@example.com → expect 422 with errors.email (duplicate)
curl -s -X POST http://localhost:8000/api/v1/auth/register \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name":"Jane Again","email":"jane@example.com","password":"Password123!","password_confirmation":"Password123!","role":"cleaner"}'
```

**Expected:** step 1 → `201`; steps 2 and 3 → `422` with the named field under
`errors`.

### Login & logout

**What it verifies & why:** the token *is* the user's authenticated session.
Login must issue one only for correct credentials (wrong password → 422), and
logout must genuinely revoke the token (not just cosmetically) — a "logged out"
token must stop working. Logout itself is also a protected route (no token →
401).

**Run just this group:**

```bash
php artisan test tests/Feature/Auth/AuthenticationTest.php
```

**Reproduce by hand** (register first, or reuse `jane@example.com`):

```bash
# 1. Log in → expect 200 with a token. Copy the token value.
curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"jane@example.com","password":"Password123!"}'

# 2. Wrong password → expect 422 with errors.email
curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"jane@example.com","password":"wrong"}'

# 3. Log out with the token from step 1 → expect 200 { "message": "Logged out." }
curl -s -X POST http://localhost:8000/api/v1/auth/logout \
  -H "Accept: application/json" -H "Authorization: Bearer PASTE_TOKEN_HERE"

# 4. Reuse the same (now revoked) token on a protected route → expect 401
curl -s http://localhost:8000/api/v1/user \
  -H "Accept: application/json" -H "Authorization: Bearer PASTE_TOKEN_HERE"
```

**Expected:** step 1 → `200`; step 2 → `422`; step 3 → `200`; step 4 → `401`
(proving the token was really revoked).

### Email verification

**What it verifies & why:** verification proves the person controls the email
address. A valid signed link verifies and redirects to the SPA (happy path); a
wrong email hash is rejected (you can't verify someone else's account by guessing
an id); an unsigned/tampered URL is rejected (the signature, not just the params,
authorizes it); and an authenticated user can resend (nobody is stuck if the
email is lost).

**Run just this group:**

```bash
php artisan test tests/Feature/Auth/EmailVerificationTest.php
```

**Reproduce by hand** — build the signed link in `tinker`:

```bash
php artisan tinker
```

```php
$user = App\Models\User::factory()->unverified()->create();

$url = Illuminate\Support\Facades\URL::temporarySignedRoute(
    'verification.verify',
    now()->addMinutes(60),
    ['id' => $user->id, 'hash' => sha1($user->email)],
);

$url;                                   // copy this URL
```

Then, outside tinker:

```bash
# Valid link → expect 302 redirect to http://localhost:5173?verified=1
curl -s -i "PASTE_SIGNED_URL_HERE" -H "Accept: application/json"

# Tampered/invalid signature → expect 403 { "message": "Invalid signature." }
curl -s -i "http://localhost:8000/api/v1/auth/verify-email/1/deadbeef" \
  -H "Accept: application/json"
```

Confirm it took effect back in tinker (`$user->fresh()->hasVerifiedEmail()`
returns `true`). To exercise **resend**, log in for a token, then:

```bash
curl -s -X POST http://localhost:8000/api/v1/auth/email/verification-notification \
  -H "Accept: application/json" -H "Authorization: Bearer PASTE_TOKEN_HERE"
```

**Expected:** valid link → `302`; bad signature → `403`; resend →
`200 { "message": "Verification link sent." }` (the resent link appears in
`storage/logs/laravel.log`).

### Password reset

**What it verifies & why:** password reset is an account-takeover surface. It
must send the link for a known email (the link actually goes out), reset the
password only with a genuine broker token (and the new password then works), and
reject an invalid/expired token (a guessed token can't change a password).

**Run just this group:**

```bash
php artisan test tests/Feature/Auth/PasswordResetTest.php
```

**Reproduce by hand:**

```bash
# 1. Request a reset link → expect 200. The link is logged to storage/logs/laravel.log
curl -s -X POST http://localhost:8000/api/v1/auth/forgot-password \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"jane@example.com"}'
```

Get a valid token — copy it from the logged link's `?token=...` param, or mint
one in tinker:

```php
// php artisan tinker
$user = App\Models\User::where('email', 'jane@example.com')->first();
Illuminate\Support\Facades\Password::createToken($user);   // copy this token
```

```bash
# 2. Reset with the real token → expect 200 { "message": "Your password has been reset." }
curl -s -X POST http://localhost:8000/api/v1/auth/reset-password \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"token":"PASTE_TOKEN_HERE","email":"jane@example.com","password":"NewPassword123!","password_confirmation":"NewPassword123!"}'

# 3. Reset with a bogus token → expect 422 with errors.email
curl -s -X POST http://localhost:8000/api/v1/auth/reset-password \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"token":"not-a-real-token","email":"jane@example.com","password":"NewPassword123!","password_confirmation":"NewPassword123!"}'

# 4. Confirm the change: log in with the NEW password → expect 200
curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"jane@example.com","password":"NewPassword123!"}'
```

**Expected:** step 1 → `200`; step 2 → `200`; step 3 → `422`; step 4 → `200` with
a fresh token, proving the new password is live.
