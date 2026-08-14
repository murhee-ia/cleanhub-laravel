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
php artisan queue:work            # second terminal; delivers queued mail/database notifications
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
| Categories        | `tests/Feature/CleaningJobCategoryTest.php`   | Only active categories are listed; guests can read them.             |
| Job browsing       | `tests/Feature/CleaningJobPostBrowseTest.php` | The open/published default, the search-vs-passive-feed split, status filtering privilege, and pagination bounds. |
| Job posting/updates| `tests/Feature/CleaningJobPostManageTest.php`, `tests/Feature/CleaningJobPostUpdateTest.php` | A draft's content stays fully editable; a published post locks content and only advances `status` forward. |
| Job detail         | `tests/Feature/CleaningJobPostShowTest.php`   | A single post is visible to the public only when published/non-removed, plus the owner's own-state exception. |
| Employer's public job history | `tests/Feature/EmployerJobListingTest.php` | `GET /employers/{id}/cleaning-job-posts` excludes drafts/removed posts and never leaks `applications_count`. |
| Profiles           | `tests/Feature/ProfileTest.php`, `tests/Feature/PublicProfileTest.php` | A profile is created lazily on first access; `email` is owner-only; documents append rather than replace. |
| Saved jobs         | `tests/Feature/SavedJobTest.php`              | Only open/published jobs can be saved; duplicate saves and cross-user unsaves are rejected; pagination bounds. |
| Applications & calendar | `tests/Feature/ApplicationTest.php`      | Every apply-rejection rule (closed, duplicate, self-apply, schedule conflict), accept/reject/withdraw transitions, and that the calendar only ever shows accepted/completed jobs. |
| Ratings            | `tests/Feature/RatingTest.php`              | Independent completion gates, one review per direction, visible-only aggregates/lists, and viewer rating state. |
| Notifications      | `tests/Feature/NotificationTest.php`        | Lifecycle dispatch, reminder targeting/idempotency, ownership scoping, filtering, and read actions. |

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

### Job posts (browse, publish, and complete)

**What it verifies & why:** the browse feed must default to `open`+`published`
for everyone but the employer market-watching by status; a post's content must
be editable while it's a draft and locked once published; status moves forward
only; and completion requires the employer's proof without cascading accepted
applications to completed.

**Run just these groups:**

```bash
php artisan test tests/Feature/CleaningJobPostBrowseTest.php
php artisan test tests/Feature/CleaningJobPostManageTest.php
php artisan test tests/Feature/CleaningJobPostUpdateTest.php
php artisan test tests/Feature/CleaningJobPostShowTest.php
php artisan test tests/Feature/EmployerJobListingTest.php
```

**Reproduce by hand** — register an employer and a cleaner first (see
Registration above), then:

```bash
# 1. Employer posts a published, open job → expect 201
curl -s -X POST http://localhost:8000/api/v1/cleaning-job-posts \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer EMPLOYER_TOKEN" \
  -d '{"title":"Hotel Housekeeping Team","cleaning_job_category_id":2,"description":"Looking for a reliable housekeeping team.","country":"Philippines","city":"Cebu City","schedule_date":"2026-09-15","start_time":"08:00","end_time":"16:00","cleaners_needed":3,"pay_amount":1500,"pay_currency":"PHP","visibility":"published"}'

# 2. Cleaner browses the default feed → expect the new post included, is_saved/has_applied both false
curl -s "http://localhost:8000/api/v1/cleaning-job-posts?per_page=50" \
  -H "Accept: application/json" -H "Authorization: Bearer CLEANER_TOKEN"

# 3. Cleaner tries to filter by status → expect 422 on the status field
curl -s "http://localhost:8000/api/v1/cleaning-job-posts?status=open" \
  -H "Accept: application/json" -H "Authorization: Bearer CLEANER_TOKEN"

# 4. Employer advances the post's status → expect 200, status now "reviewing"
curl -s -X PATCH http://localhost:8000/api/v1/cleaning-job-posts/PASTE_JOB_ID \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer EMPLOYER_TOKEN" \
  -d '{"status":"reviewing"}'

# 5. Employer tries to edit a locked (published) field → expect 422
curl -s -X PATCH http://localhost:8000/api/v1/cleaning-job-posts/PASTE_JOB_ID \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer EMPLOYER_TOKEN" \
  -d '{"title":"New Title"}'

# 6. Move to closed, then try to complete without proof → expect 422 on completion_proof
curl -s -X PATCH http://localhost:8000/api/v1/cleaning-job-posts/PASTE_JOB_ID \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer EMPLOYER_TOKEN" -d '{"status":"closed"}'
curl -s -X PATCH http://localhost:8000/api/v1/cleaning-job-posts/PASTE_JOB_ID \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer EMPLOYER_TOKEN" -d '{"status":"completed"}'

# 7. Complete with photo/PDF proof → expect 200, status "completed"
curl -s -X POST http://localhost:8000/api/v1/cleaning-job-posts/PASTE_JOB_ID \
  -H "Accept: application/json" -H "Authorization: Bearer EMPLOYER_TOKEN" \
  -F '_method=PATCH' -F 'status=completed' -F 'completion_proof=@/absolute/path/to/proof.jpg'
```

**Expected:** step 1 → `201`; step 2 → `200` with the post listed; step 3 →
`422`; step 4 → `200` with `status: "reviewing"`; step 5 → `422`; step 6's
closed transition → `200` and proof-less completion → `422`; step 7 → `200`.

### Saved jobs

**What it verifies & why:** only a currently open/published job can be saved,
a job can't be saved twice, and a saved job that later closes stays on the list
(never silently filtered out).

**Run just this group:**

```bash
php artisan test tests/Feature/SavedJobTest.php
```

**Reproduce by hand:**

```bash
# 1. Cleaner saves the job → expect 201
curl -s -X POST http://localhost:8000/api/v1/saved-jobs \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer CLEANER_TOKEN" \
  -d '{"cleaning_job_post_id": PASTE_JOB_ID}'

# 2. Save it again → expect 422 "already in your saved list"
curl -s -X POST http://localhost:8000/api/v1/saved-jobs \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer CLEANER_TOKEN" \
  -d '{"cleaning_job_post_id": PASTE_JOB_ID}'

# 3. Unsave it → expect 200
curl -s -X DELETE http://localhost:8000/api/v1/saved-jobs/PASTE_JOB_ID \
  -H "Accept: application/json" -H "Authorization: Bearer CLEANER_TOKEN"
```

**Expected:** step 1 → `201`; step 2 → `422`; step 3 → `200`.

### Applications & calendar

**What it verifies & why:** the entire apply lifecycle — a cleaner can apply
once and only once to an open job (closed-job and duplicate rejections are both
`422`s but with different messages), an employer can never apply to their own
listing (a `403` before validation even runs), a schedule that overlaps an
already-accepted job is rejected as a `409` rather than a `422`, and accepting
an application is the one and only thing that puts a job on the cleaner's
calendar.

**Run just this group:**

```bash
php artisan test tests/Feature/ApplicationTest.php
```

**Reproduce by hand** — continuing with the job from the section above (still
`open`/`published`; re-post one if it was advanced/deleted earlier):

```bash
# 1. Cleaner applies with a message → expect 201, status "pending"
curl -s -X POST http://localhost:8000/api/v1/applications \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer CLEANER_TOKEN" \
  -d '{"cleaning_job_post_id": PASTE_JOB_ID, "message": "I have five years of hotel housekeeping experience."}'

# 2. Same cleaner applies again → expect 422 "already applied"
curl -s -X POST http://localhost:8000/api/v1/applications \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer CLEANER_TOKEN" \
  -d '{"cleaning_job_post_id": PASTE_JOB_ID}'

# 3. The employer who posted it tries to apply to their own job → expect 403
curl -s -X POST http://localhost:8000/api/v1/applications \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer EMPLOYER_TOKEN" \
  -d '{"cleaning_job_post_id": PASTE_JOB_ID}'

# 4. Employer views the applicant list → expect 201's application listed
curl -s http://localhost:8000/api/v1/cleaning-job-posts/PASTE_JOB_ID/applications \
  -H "Accept: application/json" -H "Authorization: Bearer EMPLOYER_TOKEN"

# 5. Employer accepts it → expect 200, status "accepted"
curl -s -X PATCH http://localhost:8000/api/v1/applications/PASTE_APPLICATION_ID/accept \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer EMPLOYER_TOKEN" \
  -d '{"message": "You are booked in — see you on site at 8am."}'

# 6. Cleaner checks their calendar → expect the now-accepted job listed
curl -s http://localhost:8000/api/v1/calendar \
  -H "Accept: application/json" -H "Authorization: Bearer CLEANER_TOKEN"

# 7. Cleaner applies to a second job on the same date/overlapping time → expect 409
curl -s -X POST http://localhost:8000/api/v1/applications \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer CLEANER_TOKEN" \
  -d '{"cleaning_job_post_id": PASTE_OVERLAPPING_JOB_ID}'
```

**Expected:** step 1 → `201`; step 2 → `422`; step 3 → `403`; step 4 → `200`
with the application listed; step 5 → `200` with `status: "accepted"`; step 6
→ `200` with a bare array containing that job; step 7 → `409`.

To also exercise withdraw and reject: apply to a fresh job
(`POST /applications`), then `DELETE /applications/{id}` while it's still
`pending` → expect `200 { "message": "Application withdrawn." }`; separately,
have the employer `PATCH /applications/{id}/reject` on a different pending
application → expect `200` with `status: "rejected"`.

Complete each accepted relationship independently:

```bash
# Cleaner completes their application side with proof
curl -s -X POST http://localhost:8000/api/v1/applications/PASTE_APPLICATION_ID/complete \
  -H "Accept: application/json" -H "Authorization: Bearer CLEANER_TOKEN" \
  -F 'proof=@/absolute/path/to/cleaner-proof.jpg'

# Employer completes the job-post side with separate proof
curl -s -X POST http://localhost:8000/api/v1/cleaning-job-posts/PASTE_JOB_ID \
  -H "Accept: application/json" -H "Authorization: Bearer EMPLOYER_TOKEN" \
  -F '_method=PATCH' -F 'status=completed' \
  -F 'completion_proof=@/absolute/path/to/employer-proof.pdf'
```

The first response must show `application.status: "completed"`; the second
must show `job.status: "completed"`. Either can happen first, and neither
updates the other's status. The proof-upload completion endpoint is part of the
manual pass; the current application feature test primarily covers applying,
decisions, calendar behavior, and conflicts.

### Profiles

**What it verifies & why:** a profile exists (as an empty object) the first
time it's read, without a separate creation step; only the profile's own owner
ever sees its `email`; and uploaded documents accumulate rather than replace
each other on every update.

**Run just this group:**

```bash
php artisan test tests/Feature/ProfileTest.php
php artisan test tests/Feature/PublicProfileTest.php
```

**Reproduce by hand:**

```bash
# 1. Cleaner reads their own (untouched) profile → expect 200, mostly-null fields
curl -s http://localhost:8000/api/v1/profile \
  -H "Accept: application/json" -H "Authorization: Bearer CLEANER_TOKEN"

# 2. Cleaner updates bio/location/categories/languages → expect 200 with those fields set
curl -s -X PATCH http://localhost:8000/api/v1/profile \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer CLEANER_TOKEN" \
  -d '{"bio":"Detail-oriented cleaner with hotel and residential experience.","country":"Philippines","city":"Cebu City","cleaning_categories":[1,2],"languages":["English","Cebuano"]}'

# 3. The employer views the cleaner's public profile → expect 200, no email field
curl -s http://localhost:8000/api/v1/cleaners/PASTE_CLEANER_USER_ID \
  -H "Accept: application/json" -H "Authorization: Bearer EMPLOYER_TOKEN"
```

**Expected:** step 1 → `200`; step 2 → `200` reflecting the new values,
`cleaning_categories` expanded to `{id, name, slug}` objects; step 3 → `200`
with every field from step 2 except `email`.

### Ratings

**What it verifies & why:** only the two parties can review each other, each
party unlocks rating by completing their own side, duplicate ratings are
blocked independently per reviewer, hidden rows do not affect public lists or
averages, and application responses persist `viewer_has_rated` state.

**Run just this group:**

```bash
php artisan test tests/Feature/RatingTest.php
```

**Reproduce by hand** — use the accepted relationship completed on both sides
in the previous section:

```bash
# 1. Cleaner rates employer → expect 201
curl -s -X POST http://localhost:8000/api/v1/ratings \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer CLEANER_TOKEN" \
  -d '{"application_id":PASTE_APPLICATION_ID,"stars":5,"text":"Clear instructions and professional communication."}'

# 2. Same cleaner rates the same application again → expect 422 on application_id
curl -s -X POST http://localhost:8000/api/v1/ratings \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer CLEANER_TOKEN" \
  -d '{"application_id":PASTE_APPLICATION_ID,"stars":4}'

# 3. Employer rates cleaner independently → expect 201
curl -s -X POST http://localhost:8000/api/v1/ratings \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer EMPLOYER_TOKEN" \
  -d '{"application_id":PASTE_APPLICATION_ID,"stars":5}'

# 4. List visible reviews and inspect aggregate profile fields
curl -s http://localhost:8000/api/v1/cleaners/PASTE_CLEANER_USER_ID/ratings \
  -H "Accept: application/json" -H "Authorization: Bearer EMPLOYER_TOKEN"
curl -s http://localhost:8000/api/v1/cleaners/PASTE_CLEANER_USER_ID \
  -H "Accept: application/json" -H "Authorization: Bearer EMPLOYER_TOKEN"
```

**Expected:** steps 1 and 3 create separate rating rows; step 2 is rejected;
step 4 returns the employer's review in the paginated list and reflects it in
`rating_average`/`rating_count`.

To verify independent gating, repeat with only the cleaner's application side
completed: the cleaner may rate, while the employer receives `403` until the
job post is completed. Reverse the setup to verify the opposite direction.

### Notifications

**What it verifies & why:** application actions notify the correct party,
tomorrow's accepted-job reminder is targeted and idempotent, notification lists
are caller-scoped, `unread_only` is validated, and one/all read operations
cannot affect another user.

**Run just this group:**

```bash
php artisan test tests/Feature/NotificationTest.php
```

**Reproduce by hand:** keep `php artisan queue:work` running, then apply,
accept/reject, or withdraw using the earlier commands. After the queue handles
the job:

```bash
# 1. List every notification → paginated { data, links, meta }
curl -s http://localhost:8000/api/v1/notifications \
  -H "Accept: application/json" -H "Authorization: Bearer CLEANER_TOKEN"

# 2. List only unread notifications
curl -s 'http://localhost:8000/api/v1/notifications?unread_only=1' \
  -H "Accept: application/json" -H "Authorization: Bearer CLEANER_TOKEN"

# 3. Mark one caller-owned UUID read
curl -s -X PATCH http://localhost:8000/api/v1/notifications/PASTE_NOTIFICATION_UUID/read \
  -H "Accept: application/json" -H "Authorization: Bearer CLEANER_TOKEN"

# 4. Mark every remaining notification read
curl -s -X PATCH http://localhost:8000/api/v1/notifications/read-all \
  -H "Accept: application/json" -H "Authorization: Bearer CLEANER_TOKEN"

# 5. Manually run tomorrow's reminder scan twice
php artisan app:send-job-reminders
php artisan app:send-job-reminders
```

**Expected:** application acceptance/rejection reaches the cleaner; new apply
and withdrawal reach the employer. Step 3 sets `read_at`; step 4 returns
`{"message":"All notifications marked as read."}`; the second reminder-command
run sends no duplicate for the same accepted application. The scheduler invokes
the reminder command daily at `08:00` in the configured application timezone.
