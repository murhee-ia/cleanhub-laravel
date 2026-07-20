# CleanHub Backend Architecture

A practical map of the Laravel backend: **if you're looking for X, here's where
it lives.** For the wire contract see [`api.md`](api.md); for how to run and
verify things see [`testing.md`](testing.md).

> This file grows as the codebase grows. It currently reflects the auth & roles
> foundation; later rounds append rows to the tables below in place.

---

## Directory map

| Folder / Path                          | Purpose                                                                                             | Example file                                             |
| -------------------------------------- | --------------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| `routes/api.php`                       | Every endpoint, grouped under the `v1` (and `auth`) prefix. One line per route, no logic.           | `routes/api.php`                                         |
| `app/Http/Controllers/Api/V1/`         | Thin HTTP handlers, namespaced by API version; auth controllers under `Auth/`. Delegate validation. | `app/Http/Controllers/Api/V1/Auth/LoginController.php`   |
| `app/Http/Requests/`                   | Form Requests — one per write endpoint; hold `rules()` (validation) and `authorize()`.              | `app/Http/Requests/Auth/RegisterRequest.php`             |
| `app/Http/Resources/`                  | API Resources defining the exact JSON for a model. Change the wire shape here, not in controllers.  | `app/Http/Resources/UserResource.php`                    |
| `app/Models/`                          | Eloquent models: casts, relationships, role/verification helpers.                                   | `app/Models/User.php`                                    |
| `app/Enums/`                           | Backed enums for fixed value sets.                                                                  | `app/Enums/UserRole.php`                                 |
| `app/Policies/`                        | Per-model authorization policies (auto-discovered, no manual registration).                         | `app/Policies/UserPolicy.php`                            |
| `app/Notifications/`                   | Mailables/notifications, e.g. queued email verification.                                            | `app/Notifications/Auth/QueuedVerifyEmail.php`           |
| `app/Providers/AppServiceProvider.php` | Global auth wiring: admin `Gate::before`, SPA reset-link URL, default password policy.              | `app/Providers/AppServiceProvider.php`                   |
| `bootstrap/app.php`                    | Route registration, global middleware, and the rule that `/api/*` errors render as JSON.            | `bootstrap/app.php`                                      |
| `config/`                              | Framework config plus app-specific config.                                                          | `config/cleanhub.php`, `config/cors.php`                 |
| `database/migrations/`                 | Schema definitions. The `users` table carries the `role` enum column.                               | `database/migrations/0001_01_01_000000_create_users_table.php` |
| `database/factories/`                  | Test/seed data builders, including role states.                                                    | `database/factories/UserFactory.php`                     |
| `database/seeders/`                    | Seeders; the single admin is seeded here.                                                           | `database/seeders/AdminUserSeeder.php`                   |
| `tests/Feature/`, `tests/Unit/`        | Pest tests, grouped by area. Most are feature (HTTP-level) tests.                                   | `tests/Feature/Auth/RegistrationTest.php`                |

## Key decisions

| Decision                                                     | Why                                                                                                          | Where enforced                                                                                                                     |
| ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------- |
| REST API with stateless Sanctum bearer-token auth            | API and SPA are separate apps over HTTP; a header token stays stateless and avoids cookie/CSRF coupling.     | `auth:sanctum` middleware in `routes/api.php`; `HasApiTokens` on `User`; JSON errors for `/api/*` in `bootstrap/app.php`. No CSRF. |
| API versioning under `/v1`                                   | Lets breaking changes ship later under `/v2` without breaking existing clients.                             | `Route::prefix('v1')` in `routes/api.php`; controllers namespaced `App\Http\Controllers\Api\V1`.                                   |
| Role stored as an enum on the `users` table                  | Each user has exactly one role from a fixed set — one column beats a join table; a PHP enum adds type safety.| `role` enum column in the users migration; `App\Enums\UserRole` cast on `User`; `RegisterRequest` limits self-registration.        |
| Admin is a super-user, and exactly one admin exists          | The admin has full control and is never self-registered.                                                     | `Gate::before` in `AppServiceProvider` grants admin every ability; `AdminUserSeeder` is idempotent and keyed on the admin role.    |
| Prefer soft deletes for admin-facing destructive actions     | Destructive admin actions should be recoverable and auditable, not permanent.                               | Project-wide convention (root `CLAUDE.md`). No such action exists yet; affected models will use the `SoftDeletes` trait.           |
| Frontend/backend split for email links                       | Verification is a backend concern (verify, then redirect to SPA); reset needs the SPA to collect the password.| `VerifyEmailController` redirects to `FRONTEND_URL?verified=1`; `ResetPassword::createUrlUsing` (in `AppServiceProvider`) targets `FRONTEND_URL/reset-password`. |

## Domain model (current)

Only one domain model exists so far; this section grows as tables are added.

- **User** — `id`, `name`, `email`, `password`, `role` (`UserRole` enum:
  `cleaner`/`employer`/`moderator`/`admin`), `email_verified_at`, timestamps.
  Implements `MustVerifyEmail`; holds Sanctum tokens via `HasApiTokens`.
