<?php

use App\Models\User;
use App\Notifications\Auth\QueuedVerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

test('a user can verify their email via a signed link', function () {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);

    $this->get($url)->assertRedirect(config('cleanhub.frontend_url').'?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('verification fails with an invalid hash', function () {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1('someone-else@example.com'),
    ]);

    $this->get($url)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('verification fails without a valid signature', function () {
    $user = User::factory()->unverified()->create();

    $this->get("/api/v1/auth/verify-email/{$user->id}/".sha1($user->email))->assertForbidden();
});

test('an authenticated user can resend the verification email', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/email/verification-notification')->assertOk();

    Notification::assertSentTo($user, QueuedVerifyEmail::class);
});
