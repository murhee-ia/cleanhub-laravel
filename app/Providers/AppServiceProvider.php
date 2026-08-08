<?php

namespace App\Providers;

use App\Models\CleaningJobPost;
use App\Models\Rating;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuth();
        $this->configureMorphMap();
    }

    /**
     * Pin the polymorphic types that reports and audit logs point at to short,
     * stable aliases. Storing 'user'/'job_post'/'rating' instead of fully
     * qualified class names keeps the API filter values clean and survives a
     * later class rename without a data migration. Enforcing the map is strict:
     * every model used polymorphically must appear here, so a report (which an
     * audit-log entry points back at) needs its own alias too.
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'job_post' => CleaningJobPost::class,
            'rating' => Rating::class,
            'report' => Report::class,
        ]);
    }

    /**
     * Grant the admin every ability and point auth emails at the SPA.
     */
    protected function configureAuth(): void
    {
        Gate::before(fn (User $user): ?bool => $user->isAdmin() ? true : null);

        ResetPassword::createUrlUsing(fn (User $user, string $token): string => config('cleanhub.frontend_url')
            .'/reset-password?token='.$token
            .'&email='.urlencode($user->getEmailForPasswordReset()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        JsonResource::withoutWrapping();

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
