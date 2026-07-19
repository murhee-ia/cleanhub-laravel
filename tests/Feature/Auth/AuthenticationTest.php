<?php

use App\Models\User;

test('a user can log in and receive a token', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'email_verified_at']])
        ->assertJsonPath('user.email', $user->email);
});

test('login fails with an incorrect password', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('email');
});

test('a user can log out and the current token is revoked', function () {
    $user = User::factory()->create();
    $token = $user->createToken('auth')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

test('logout requires authentication', function () {
    $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
});
