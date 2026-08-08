<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('an admin can list users including suspended and deleted ones', function () {
    User::factory()->count(3)->create();
    User::factory()->create(['suspended_at' => now()]);
    User::factory()->create(['deleted_at' => now()]);
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/v1/admin/users')
        ->assertOk()
        ->assertJsonCount(6, 'data'); // 3 + suspended + deleted + the admin
});

test('the user list can be filtered by role, status, and search', function () {
    User::factory()->employer()->create(['name' => 'Acme Cleaning Co']);
    User::factory()->cleaner()->create();
    User::factory()->create(['suspended_at' => now()]);
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/v1/admin/users?role=employer')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/users?status=suspended')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/users?search=Acme')->assertOk()->assertJsonCount(1, 'data');
});

test('non-admins cannot reach the admin user endpoints', function (string $role) {
    Sanctum::actingAs(User::factory()->state(['role' => $role])->create());

    $this->getJson('/api/v1/admin/users')->assertForbidden();
})->with(['cleaner', 'employer', 'moderator']);

test('an admin can suspend a user, which revokes tokens and blocks login', function () {
    $user = User::factory()->cleaner()->create();
    $user->createToken('auth');
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/admin/users/{$user->id}/suspend")
        ->assertOk()
        ->assertJsonPath('is_suspended', true);

    expect($user->fresh()->isSuspended())->toBeTrue();
    expect($user->tokens()->count())->toBe(0);
    expect(AuditLog::where('action', 'user.suspended')->exists())->toBeTrue();

    // A fresh, unauthenticated login attempt is refused while suspended.
    app()['auth']->forgetGuards();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('email');
});

test('an admin can reactivate a suspended user', function () {
    $user = User::factory()->create(['suspended_at' => now()]);
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->patchJson("/api/v1/admin/users/{$user->id}/reactivate")
        ->assertOk()
        ->assertJsonPath('is_suspended', false);

    expect($user->fresh()->isSuspended())->toBeFalse();
});

test('an admin can change a user role but never to admin', function () {
    $user = User::factory()->cleaner()->create();
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->patchJson("/api/v1/admin/users/{$user->id}/role", ['role' => 'moderator'])
        ->assertOk()
        ->assertJsonPath('role', 'moderator');
    expect($user->fresh()->role)->toBe(UserRole::Moderator);
    expect(AuditLog::where('action', 'user.role_changed')->exists())->toBeTrue();

    $this->patchJson("/api/v1/admin/users/{$user->id}/role", ['role' => 'admin'])
        ->assertStatus(422)->assertJsonValidationErrorFor('role');
});

test('an admin can soft delete and restore a user', function () {
    $user = User::factory()->cleaner()->create();
    $user->createToken('auth');
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->deleteJson("/api/v1/admin/users/{$user->id}")
        ->assertOk()
        ->assertJsonPath('is_deleted', true);

    // Row survives but drops out of normal queries; tokens revoked.
    expect(User::find($user->id))->toBeNull();
    expect(User::withTrashed()->find($user->id))->not->toBeNull();
    expect($user->tokens()->count())->toBe(0);

    $this->patchJson("/api/v1/admin/users/{$user->id}/restore")
        ->assertOk()
        ->assertJsonPath('is_deleted', false);
    expect(User::find($user->id))->not->toBeNull();
});

test('the admin account cannot be suspended, deleted, or re-roled', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->admin()->create(); // guard is by role, not identity
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/admin/users/{$target->id}/suspend")->assertForbidden();
    $this->deleteJson("/api/v1/admin/users/{$target->id}")->assertForbidden();
    $this->patchJson("/api/v1/admin/users/{$target->id}/role", ['role' => 'cleaner'])->assertForbidden();
});
