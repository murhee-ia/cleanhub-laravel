# CleanHub Backend Architecture

A practical map of the Laravel backend: **if you're looking for X, here's where
it lives.** For the wire contract see [`api.md`](api.md); for how to run and
verify things see [`testing.md`](testing.md).

> This file is updated as the codebase grows — new rows are appended to the
> tables below in place rather than superseding them.

---

## Runtime and project-level files

These files form the outer shell around the application code:

| Path | Purpose |
|---|---|
| `public/index.php` | Web-server entry point. It loads Composer and hands the request to the application created by `bootstrap/app.php`. |
| `bootstrap/providers.php` | Lists application service providers loaded during boot. `AppServiceProvider` performs CleanHub's cross-cutting setup. |
| `phpunit.xml`, `tests/Pest.php` | Configure the test environment and Pest bootstrap used by the test suites. |
| `phpstan.neon`, `pint.json` | Configure static analysis and PHP formatting; they support code quality but do not handle requests at runtime. |

The two entry paths—HTTP through `public/index.php`, console through `artisan`—
both boot the same container and configuration. That is why a controller, queue
worker, scheduled reminder, and test can reuse the same models, policies, and
notification classes instead of maintaining separate versions of the domain.

## Directory map

| Folder / Path                          | Purpose                                                                                             | Example file                                             |
| -------------------------------------- | --------------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| `routes/api.php`                       | Every endpoint, grouped under the `v1` (and `auth`) prefix. One line per route, no logic.           | `routes/api.php`                                         |
| `routes/console.php`                   | Scheduled command registration. The job-reminder command is scheduled here, separate from HTTP routes. | `routes/console.php`                                  |
| `app/Http/Controllers/Api/V1/`         | Thin HTTP handlers, namespaced by API version; auth controllers under `Auth/`. Delegate validation. | `app/Http/Controllers/Api/V1/CleaningJobPostController.php` |
| `app/Http/Requests/`                   | Form Requests — one per write endpoint; hold `rules()` (validation) and `authorize()`.              | `app/Http/Requests/StoreApplicationRequest.php`          |
| `app/Http/Resources/`                  | API Resources defining the exact JSON for a model. Change the wire shape here, not in controllers.  | `app/Http/Resources/CleaningJobPostResource.php`         |
| `app/Models/`                          | Eloquent models: casts, relationships, role/verification helpers, query scopes.                     | `app/Models/CleaningJobPost.php`                         |
| `app/Enums/`                           | Backed enums for fixed value sets.                                                                  | `app/Enums/ApplicationStatus.php`                        |
| `app/Policies/`                        | Per-model authorization policies (auto-discovered, no manual registration).                         | `app/Policies/ApplicationPolicy.php`                     |
| `app/Notifications/`                   | Queued mail/database notifications. Auth mail and application events live in separate subfolders; shared base classes keep each event's payload consistent. | `app/Notifications/Applications/ApplicationNotification.php` |
| `app/Console/Commands/`                | Application commands run manually or by Laravel's scheduler. Reminder delivery checks existing notification rows so reruns are safe. | `app/Console/Commands/SendJobReminders.php` |
| `app/Rules/`                           | Reusable validation rules that do not belong to one request.                                        | `app/Rules/MaxWords.php`                                 |
| `app/Providers/AppServiceProvider.php` | Global auth wiring: admin `Gate::before`, SPA reset-link URL, default password policy.              | `app/Providers/AppServiceProvider.php`                   |
| `bootstrap/app.php`                    | Route registration, global middleware, and the rule that `/api/*` errors render as JSON.            | `bootstrap/app.php`                                      |
| `config/`                              | Framework config plus app-specific config.                                                          | `config/cleanhub.php`, `config/cors.php`                 |
| `database/migrations/`                 | Schema definitions. The `users` table carries the `role` enum column; every job-related table is prefixed `cleaning_` (see below). | `database/migrations/0001_01_01_000000_create_users_table.php` |
| `database/factories/`                  | Test/seed data builders, including role and status states.                                          | `database/factories/CleaningJobPostFactory.php`          |
| `database/seeders/`                    | Seeders; the single admin and the fixed category list are seeded here, plus local-only demo data.   | `database/seeders/AdminUserSeeder.php`                   |
| `storage/app/public/`                  | Uploaded photos, documents, job media, resumes, and completion proofs. Public URLs require `php artisan storage:link`. | application/job/profile upload folders |
| `tests/Feature/`, `tests/Unit/`        | Pest tests, grouped by area. Most are feature (HTTP-level) tests.                                   | `tests/Feature/ApplicationTest.php`                      |

## How a request moves through the backend

The route/controller/request/resource split is intentional. Think of it as a
reception desk followed by specialist rooms:

1. `routes/api.php` recognizes the URL and sends the request to the right
   controller method.
2. `auth:sanctum`, a Form Request, and/or a policy establishes who the caller
   is, whether they may perform the action, and whether the input is valid.
3. The controller coordinates the use case: query models, store a file, perform
   a status transition, or dispatch a notification.
4. Eloquent models and query scopes describe relationships and reusable data
   access. They do not decide presentation.
5. An API Resource turns the result into the stable JSON shape described in
   [`api.md`](api.md).

Keeping those jobs separate is what lets a field rule change without rewriting
a controller, or lets the JSON representation evolve without changing the
database query that produced it.

## Key decisions

| Decision                                                     | Why                                                                                                          | Where enforced                                                                                                                     |
| ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------- |
| REST API with stateless Sanctum bearer-token auth            | API and SPA are separate apps over HTTP; a header token stays stateless and avoids cookie/CSRF coupling.     | `auth:sanctum` middleware in `routes/api.php`; `HasApiTokens` on `User`; JSON errors for `/api/*` in `bootstrap/app.php`. No CSRF. |
| API versioning under `/v1`                                   | Lets breaking changes ship later under `/v2` without breaking existing clients.                             | `Route::prefix('v1')` in `routes/api.php`; controllers namespaced `App\Http\Controllers\Api\V1`.                                   |
| Role stored as an enum on the `users` table                  | Each user has exactly one role from a fixed set — one column beats a join table; a PHP enum adds type safety.| `role` enum column in the users migration; `App\Enums\UserRole` cast on `User`; `RegisterRequest` limits self-registration.        |
| Admin is a super-user, and exactly one admin exists          | The admin has full control and is never self-registered.                                                     | `Gate::before` in `AppServiceProvider` grants admin every ability; `AdminUserSeeder` is idempotent and keyed on the admin role.    |
| Prefer soft deletes for admin-facing destructive actions     | Destructive admin actions should be recoverable and auditable, not permanent.                               | `CleaningJobPost` uses `SoftDeletes`; its `DELETE` endpoint is admin-only via a policy that denies everyone else outright (the admin's `Gate::before` bypass is the sole path through). |
| Frontend/backend split for email links                       | Verification is a backend concern (verify, then redirect to SPA); reset needs the SPA to collect the password.| `VerifyEmailController` redirects to `FRONTEND_URL?verified=1`; `ResetPassword::createUrlUsing` (in `AppServiceProvider`) targets `FRONTEND_URL/reset-password`. |
| Job-related tables are prefixed `cleaning_`, never bare `jobs` | Laravel's own queue system already owns a `jobs` table (`0001_01_01_000002_create_jobs_table.php`) — reusing that name would collide. | `cleaning_job_posts`, `cleaning_job_categories` migrations; `CleaningJobPost`/`CleaningJobCategory` models. |
| `visibility` and `status` are two separate columns on a job post, never merged | They answer different questions — is this live yet, versus where is it in its lifecycle — and merging them would make "draft but somehow closed" representable when it shouldn't be. | `CleaningJobPost` casts both to their own enum; `UpdateCleaningJobPostRequest` locks `status` while a post is a draft and locks everything else once published. |
| A published job post cannot move backward, and `completed` is terminal | The lifecycle (`open → reviewing → closed → completed`) models real-world progress. A post may skip forward, but it cannot reopen or be un-completed after either party may have acted on that state. | `UpdateCleaningJobPostRequest::forwardOnlyStatus()`, a validation closure comparing the requested status against a fixed order map. |
| A cleaner's viewer-specific flags (`is_saved`, `has_applied`, `application_status`) are attached via query scopes, not per-row lookups | A list of 50 job posts would otherwise need 100+ extra queries (one saved-check and one applied-check per row) just to render badges. | `CleaningJobPost::scopeWithViewerSaved()` / `scopeWithViewerApplication()` — each adds one `withExists`/`withMax` subquery to the whole list's query, not one query per row. |
| An application row is never deleted, only moved through statuses | The unique `(cleaning_job_post_id, user_id)` constraint is what permanently blocks a second application to the same job — deleting a withdrawn/rejected row would silently reopen that door. | `Application` migration's unique index; `ApplicationController::destroy()` (withdraw) and `JobApplicantController::decide()` (accept/reject) both call `update()`, never `delete()`. |
| Applying is gated on the cleaner role alone, with no separate "not your own job" check | Roles are fixed and mutually exclusive — the employer who owns a post can never also hold the cleaner role needed to apply, so a dedicated ownership check would guard a code path that can't be reached. | `ApplicationPolicy::create()`; `StoreApplicationRequest::authorize()`. |
| A schedule conflict on apply is a `409`, not a `422` | It isn't that any single field is invalid — the request is only rejected because of something else the caller already committed to (an accepted job on an overlapping date). A distinct status code lets a client branch on it without parsing message text. | `ApplicationController::conflictsWithAcceptedSchedule()`; the `409` response shares the same `{message, errors}` shape as a `422` so existing field-error handling still works. |
| No separate calendar table | Accepting an application is the only event that should ever put a job on a cleaner's calendar — a second table just to mirror that would be one more place for the two to drift out of sync. | `ApplicationController::calendar()` reads `Application` rows with `status` in `[accepted, completed]`, joined to their job post, directly. |
| Cleaner and employer completion are independent | Neither party should be able to block the other's rating action by delaying their own completion. The cleaner completes the application; the employer completes the job post; both require proof. | `ApplicationController::complete()`, `CleaningJobPostController::update()`, the two `completion_proof_path` columns, and `RatingPolicy::review()`. |
| Completing a job post does not complete its accepted applications | One employer action must not silently claim that every cleaner finished. Each accepted cleaner owns the transition of their own application. | `CleaningJobPostController::update()` changes only the post; `POST /applications/{id}/complete` changes one application. |
| Ratings are one row per direction | Cleaner→employer and employer→cleaner are two independent opinions about the same relationship, so one must not overwrite or block the other. | Unique `(application_id, reviewer_id)` index; `RatingController` derives `reviewee_id`; `RatingPolicy` checks the caller's own completion. |
| Public rating totals use visible reviews only | A hidden review must not continue affecting the trust summary shown on profiles and job cards. | `Rating::visible()`, profile resources, and the employer/cleaner rating query helpers. |
| Laravel's notification table is the notification source of truth | The framework already provides ownership, unread queries, timestamps, and mark-as-read behavior; a second custom table would duplicate those concepts. | `Notifiable` on `User`, `NotificationResource`, and `NotificationController`. |
| Application notifications share one base payload | The frontend can render and route every application event if each row consistently carries a type, message, application id, and job-post id. | `ApplicationNotification` plus its accepted/rejected/new-applicant/withdrawn/reminder subclasses. |
| Job reminders are idempotent | The scheduler or a developer may run the command more than once; the cleaner should still receive one reminder for that accepted application. | `SendJobReminders` checks existing `JobReminder` rows before dispatch and is scheduled in `routes/console.php`. |
| `per_page` has a floor of 50, not just a ceiling | Every list this API serves backs a feed or table the frontend wants filled in one round trip — a tiny page size would just mean more requests for the same total data. | `Controller::perPageRule()`, a shared helper every paginated list endpoint's validation calls into. |
| `GET /cleaning-job-categories` and `GET /calendar` skip the pagination envelope entirely | Both return a small, bounded set the caller always wants in full (the whole category list; one cleaner's whole calendar) — wrapping them in `data`/`meta` would add structure with nothing to describe. | `JsonResource::withoutWrapping()` in `AppServiceProvider` — every *other* list endpoint stays wrapped because pagination always adds its own `data`/`meta`/`links`, independent of this setting; these two are the only unpaginated collections in the API, so they're the only ones it actually affects. |

## Domain model (current)

- **User** — `id`, `name`, `email`, `password`, `role` (`UserRole` enum:
  `cleaner`/`employer`/`moderator`/`admin`), `email_verified_at`, timestamps.
  Implements `MustVerifyEmail`; holds Sanctum tokens via `HasApiTokens`; receives
  notifications via `Notifiable`. A user has one role at a time, so policies can
  make role checks without joining a separate permissions table.

- **CleanerProfile** / **EmployerProfile** — one-to-one with `User`, keyed on
  `user_id`, created lazily on first read/write (`firstOrCreate()`) rather than
  at registration time, so a fresh account doesn't need a placeholder row it
  might never touch. `CleanerProfile` additionally has a many-to-many to
  `CleaningJobCategory` (the categories a cleaner is willing to work in) via a
  pivot table. Both store an array of `{name, path}` document records for
  uploaded PDFs, appended to (never overwritten by) each profile update. Their
  resources calculate public rating averages/counts from visible ratings;
  cleaner completed-job counts require both sides to be complete.

- **CleaningJobCategory** — `id`, `name`, `slug`, `is_active`. An admin-managed
  lookup table seeded with a fixed starting set (residential, hotel, hospital,
  office, factory, event cleanup, public space, research facility). Deactivating
  a category hides it from the pickable list without breaking any job post that
  already references it.

- **CleaningJobPost** — table `cleaning_job_posts` (see the naming decision
  above), belongs to an `employer` (`User`) and a `CleaningJobCategory`. Carries
  `visibility` (`draft`/`published`) and `status`
  (`open`/`reviewing`/`closed`/`completed`/`removed`) as two independent enum
  columns — see the key decisions table for why they're kept separate and why
  `status` only moves forward. Also holds the schedule (`schedule_date`,
  `start_time`, `end_time`), location, pay (display-only — see root project
  notes on payment scope), an array of `{name, path}` uploaded images, and the
  employer's `completion_proof_path`. Uses `SoftDeletes`. Completing this model
  unlocks the employer's ability to rate accepted cleaners but deliberately
  leaves each application status unchanged.

- **SavedJob** — a pivot between a cleaner (`User`) and a `CleaningJobPost`,
  unique on `(user_id, cleaning_job_post_id)`. Exists purely to record "this
  cleaner bookmarked this post" — no status of its own.

- **Application** — belongs to a `CleaningJobPost` and a cleaner `User`.
  `status` (`ApplicationStatus` enum: `pending`/`accepted`/`rejected`/
  `withdrawn`/`completed`), an optional `message` from the cleaner and
  `resume_path`, the cleaner's `completion_proof_path`, a `decision_message`
  the employer can write back (readable by both sides), and a `private_note`
  visible to the employer alone. Unique on `(cleaning_job_post_id, user_id)` —
  see the key decisions table for why this row is never deleted once created.
  `ApplicationResource` also exposes `job_completed` and conditionally exposes
  `viewer_has_rated`, letting either side render the correct next action without
  a separate permission endpoint.

- **Rating** — belongs to an `Application`, a reviewer `User`, and a reviewee
  `User`. It stores 1–5 stars, optional text, and `visible`/`hidden` status. The
  controller derives the reviewee from the caller's side of the application;
  clients never choose an arbitrary reviewee. `RatingResource` includes compact
  reviewer/reviewee details, job context, and `other_side_completed` so readers
  understand whether the other party has finished their side.

- **Database notification** — Laravel's built-in UUID-backed notification row,
  reached through `User::notifications()` / `unreadNotifications()`. Application
  events store `type`, `message`, `application_id`, and
  `cleaning_job_post_id`; `NotificationResource` flattens those values beside
  `id`, `read_at`, and `created_at`. The API always scopes lookup through the
  authenticated user's relation, so another user's notification id behaves as
  not found.

## Completion, rating, and notification relationship

Acceptance is the fork point that connects these parts of the system:

```text
Employer accepts an application
├── application enters the cleaner's calendar
├── cleaner uploads proof → application completed → cleaner may rate employer
└── employer uploads proof → job post completed → employer may rate cleaner
```

The lower two branches may happen in either order. Application actions also
dispatch queued mail/database notifications: applying and withdrawing notify
the employer; accepting and rejecting notify the cleaner. The scheduled
reminder command handles accepted jobs due the next day. This keeps immediate
HTTP work small while the queue worker performs delivery.
