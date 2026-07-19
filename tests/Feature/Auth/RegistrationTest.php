<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\Auth\QueuedVerifyEmail;
use Illuminate\Support\Facades\Notification;

test('a cleaner can register and receive a token', function () {
    Notification::fake();

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Jane Cleaner',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'cleaner',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'email_verified_at']])
        ->assertJsonPath('user.role', 'cleaner')
        ->assertJsonPath('user.email_verified_at', null);

    $user = User::where('email', 'jane@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(UserRole::Cleaner);

    Notification::assertSentTo($user, QueuedVerifyEmail::class);
});

test('an employer can register', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Acme Cleaning Co',
        'email' => 'hr@acme.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'employer',
    ]);

    $response->assertCreated()->assertJsonPath('user.role', 'employer');
});

test('registration rejects privileged roles', function (string $role) {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Sneaky User',
        'email' => 'sneaky@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => $role,
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('role');

    expect(User::where('email', 'sneaky@example.com')->exists())->toBeFalse();
})->with(['moderator', 'admin']);

test('registration rejects a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Duplicate',
        'email' => 'taken@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'cleaner',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('email');
});

test('registration requires a matching password confirmation', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'No Confirm',
        'email' => 'noconfirm@example.com',
        'password' => 'password',
        'password_confirmation' => 'different',
        'role' => 'cleaner',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('password');
});
