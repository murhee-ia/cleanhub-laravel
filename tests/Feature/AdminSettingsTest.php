<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\CleaningJobPost;
use App\Models\Rating;
use App\Models\Report;
use App\Models\Setting;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('settings return their defaults before anything is stored', function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/v1/admin/settings')
        ->assertOk()
        ->assertJsonPath('max_file_size_mb', Setting::DEFAULTS['max_file_size_mb'])
        ->assertJsonPath('max_active_applications', Setting::DEFAULTS['max_active_applications']);
});

test('an admin can update a setting and it is persisted and audited', function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->patchJson('/api/v1/admin/settings', ['max_file_size_mb' => 12])
        ->assertOk()
        ->assertJsonPath('max_file_size_mb', 12);

    expect(Setting::getValue('max_file_size_mb'))->toBe(12);
    expect(AuditLog::where('action', 'settings.updated')->exists())->toBeTrue();
});

test('an unknown setting key is ignored and a non-integer is rejected', function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    // Unknown key isn't in the rules, so it's never written.
    $this->patchJson('/api/v1/admin/settings', ['nonsense_key' => 5])->assertOk();
    expect(Setting::where('key', 'nonsense_key')->exists())->toBeFalse();

    $this->patchJson('/api/v1/admin/settings', ['max_file_size_mb' => 'lots'])
        ->assertStatus(422)->assertJsonValidationErrorFor('max_file_size_mb');
});

test('a moderator cannot read or change settings', function () {
    Sanctum::actingAs(User::factory()->moderator()->create());

    $this->getJson('/api/v1/admin/settings')->assertForbidden();
    $this->patchJson('/api/v1/admin/settings', ['max_file_size_mb' => 1])->assertForbidden();
});

test('the overview returns platform-wide counts', function () {
    // The factories cascade (an application spins up its own post and users,
    // a rating its own application, ...), so hardcoded totals would be
    // brittle. Assert instead that the endpoint reports the true live counts —
    // that is the actual contract of the overview.
    CleaningJobPost::factory()->count(3)->create();
    Application::factory()->count(4)->create();
    Rating::factory()->create();
    Report::factory()->create();
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->assertJsonPath('users.total', User::count())
        ->assertJsonPath('jobs', CleaningJobPost::count())
        ->assertJsonPath('applications', Application::count())
        ->assertJsonPath('ratings', Rating::count())
        ->assertJsonPath('reports.total', Report::count());
});

test('an admin can read and filter the audit log', function () {
    $admin = User::factory()->admin()->create();
    AuditLog::factory()->create(['action' => 'user.suspended', 'user_id' => $admin->id]);
    AuditLog::factory()->create(['action' => 'report.resolved']);
    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/audit-logs')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/admin/audit-logs?action=suspended')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/admin/audit-logs?actor_id={$admin->id}")->assertOk()->assertJsonCount(1, 'data');
});

test('a moderator cannot read the audit log', function () {
    Sanctum::actingAs(User::factory()->moderator()->create());

    $this->getJson('/api/v1/admin/audit-logs')->assertForbidden();
});
