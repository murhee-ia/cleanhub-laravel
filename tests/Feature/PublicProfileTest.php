<?php

use App\Models\CleanerProfile;
use App\Models\CleaningJobCategory;
use App\Models\EmployerProfile;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('guests cannot view public profiles', function () {
    $cleaner = User::factory()->cleaner()->create();
    $employer = User::factory()->employer()->create();

    $this->getJson("/api/v1/cleaners/{$cleaner->id}")->assertUnauthorized();
    $this->getJson("/api/v1/employers/{$employer->id}")->assertUnauthorized();
});

test('an authenticated user can view a cleaner public profile without the email', function () {
    $cleaner = User::factory()->cleaner()->create();
    $profile = CleanerProfile::factory()->for($cleaner)->create(['city' => 'Cebu']);
    $profile->cleaningJobCategories()->attach(CleaningJobCategory::factory()->create()->id);

    Sanctum::actingAs(User::factory()->employer()->create());

    $response = $this->getJson("/api/v1/cleaners/{$cleaner->id}");

    $response->assertOk()
        ->assertJsonPath('user_id', $cleaner->id)
        ->assertJsonPath('full_name', $cleaner->name)
        ->assertJsonPath('city', 'Cebu')
        ->assertJsonCount(1, 'cleaning_categories')
        ->assertJsonMissingPath('email');
});

test('an authenticated user can view an employer public profile without the email', function () {
    $employer = User::factory()->employer()->create();
    EmployerProfile::factory()->for($employer)->create(['employer_type' => 'company']);

    Sanctum::actingAs(User::factory()->cleaner()->create());

    $response = $this->getJson("/api/v1/employers/{$employer->id}");

    $response->assertOk()
        ->assertJsonPath('user_id', $employer->id)
        ->assertJsonPath('employer_type', 'company')
        ->assertJsonMissingPath('email');
});

test('the owner viewing their own public profile still sees their email', function () {
    $cleaner = User::factory()->cleaner()->create();
    CleanerProfile::factory()->for($cleaner)->create();

    Sanctum::actingAs($cleaner);

    $this->getJson("/api/v1/cleaners/{$cleaner->id}")
        ->assertOk()
        ->assertJsonPath('email', $cleaner->email);
});

test('a public profile is returned even before the profile row exists', function () {
    $cleaner = User::factory()->cleaner()->create();
    Sanctum::actingAs(User::factory()->employer()->create());

    $this->getJson("/api/v1/cleaners/{$cleaner->id}")
        ->assertOk()
        ->assertJsonPath('user_id', $cleaner->id)
        ->assertJsonPath('bio', null)
        ->assertJsonCount(0, 'cleaning_categories');

    expect($cleaner->cleanerProfile()->exists())->toBeFalse();
});

test('requesting the wrong role or a missing user returns 404', function () {
    $employer = User::factory()->employer()->create();
    Sanctum::actingAs(User::factory()->cleaner()->create());

    $this->getJson("/api/v1/cleaners/{$employer->id}")->assertNotFound();
    $this->getJson('/api/v1/employers/999999')->assertNotFound();
});
