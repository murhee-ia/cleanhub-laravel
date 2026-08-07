<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('an admin can create a moderator account', function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->postJson('/api/v1/admin/moderators', [
        'name' => 'Mod Squad',
        'email' => 'mod@cleanhub.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])
        ->assertCreated()
        ->assertJsonPath('role', 'moderator');

    $moderator = User::where('email', 'mod@cleanhub.test')->sole();
    expect($moderator->role)->toBe(UserRole::Moderator);
    expect($moderator->email_verified_at)->not->toBeNull();
    expect(AuditLog::where('action', 'moderator.created')->exists())->toBeTrue();
});

test('a moderator cannot create another moderator', function () {
    Sanctum::actingAs(User::factory()->moderator()->create());

    $this->postJson('/api/v1/admin/moderators', [
        'name' => 'Nope',
        'email' => 'nope@cleanhub.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertForbidden();
});

test('an admin can list moderators including revoked ones', function () {
    User::factory()->moderator()->count(2)->create();
    User::factory()->moderator()->create(['deleted_at' => now()]);
    Sanctum::actingAs(User::factory()->admin()->create());

    // Unpaginated small list — a bare array, no data/meta envelope.
    $this->getJson('/api/v1/admin/moderators')->assertOk()->assertJsonCount(3);
});

test('an admin can revoke a moderator via soft delete', function () {
    $moderator = User::factory()->moderator()->create();
    $moderator->createToken('auth');
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->deleteJson("/api/v1/admin/moderators/{$moderator->id}")
        ->assertOk()
        ->assertJsonPath('is_deleted', true);

    expect(User::find($moderator->id))->toBeNull();
    expect(User::withTrashed()->find($moderator->id))->not->toBeNull();
    expect($moderator->tokens()->count())->toBe(0);
    expect(AuditLog::where('action', 'moderator.revoked')->exists())->toBeTrue();
});

test('revoking a non-moderator through the moderator endpoint is rejected', function () {
    $cleaner = User::factory()->cleaner()->create();
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->deleteJson("/api/v1/admin/moderators/{$cleaner->id}")->assertStatus(422);
    expect(User::find($cleaner->id))->not->toBeNull();
});
