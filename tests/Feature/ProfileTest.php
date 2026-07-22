<?php

use App\Models\CleaningJobCategory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

test('guests cannot read the own-profile endpoint', function () {
    $this->getJson('/api/v1/profile')->assertUnauthorized();
});

test('a cleaner reads their own profile including their email', function () {
    $user = User::factory()->cleaner()->create();
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/profile');

    $response->assertOk()
        ->assertJsonPath('user_id', $user->id)
        ->assertJsonPath('full_name', $user->name)
        ->assertJsonPath('email', $user->email)
        ->assertJsonPath('rating_average', null)
        ->assertJsonPath('completed_jobs_count', 0)
        ->assertJsonStructure(['id', 'cleaning_categories', 'languages', 'documents', 'photo_url']);
});

test('an employer reads their own profile shaped for employers', function () {
    $user = User::factory()->employer()->create();
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/profile');

    $response->assertOk()
        ->assertJsonPath('user_id', $user->id)
        ->assertJsonPath('email', $user->email)
        ->assertJsonStructure(['id', 'employer_type', 'contact_person_name', 'contact_person_contact', 'posted_jobs_count']);
});

test('reading the own profile creates the profile row on first access', function () {
    $user = User::factory()->cleaner()->create();
    Sanctum::actingAs($user);

    expect($user->cleanerProfile()->exists())->toBeFalse();

    $this->getJson('/api/v1/profile')->assertOk();

    expect($user->cleanerProfile()->exists())->toBeTrue();
});

test('moderators and admins have no profile', function () {
    Sanctum::actingAs(User::factory()->moderator()->create());
    $this->getJson('/api/v1/profile')->assertNotFound();

    Sanctum::actingAs(User::factory()->admin()->create());
    $this->getJson('/api/v1/profile')->assertNotFound();
});

test('a cleaner updates their own profile via multipart method spoofing', function () {
    Storage::fake('public');
    $user = User::factory()->cleaner()->create();
    Sanctum::actingAs($user);
    $categories = CleaningJobCategory::factory()->count(2)->create();

    $response = $this->post('/api/v1/profile', [
        '_method' => 'PATCH',
        'bio' => 'Reliable office cleaner.',
        'country' => 'Philippines',
        'city' => 'Cebu',
        'languages' => ['english', 'cebuano'],
        'cleaning_categories' => $categories->pluck('id')->all(),
        'photo' => UploadedFile::fake()->image('me.jpg'),
        'documents' => [UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf')],
    ]);

    $response->assertOk()
        ->assertJsonPath('bio', 'Reliable office cleaner.')
        ->assertJsonPath('city', 'Cebu')
        ->assertJsonCount(2, 'cleaning_categories')
        ->assertJsonPath('documents.0.name', 'resume.pdf');

    $profile = $user->cleanerProfile()->first();
    expect($profile->cleaningJobCategories()->count())->toBe(2);
    Storage::disk('public')->assertExists($profile->photo_path);
});

test('bio over 101 words is rejected', function () {
    $user = User::factory()->cleaner()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/profile', [
        '_method' => 'PATCH',
        'bio' => str_repeat('word ', 102),
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('bio');
});

test('a bio of exactly 101 words is accepted', function () {
    Storage::fake('public');
    $user = User::factory()->cleaner()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/profile', [
        '_method' => 'PATCH',
        'bio' => trim(str_repeat('word ', 101)),
    ]);

    $response->assertOk();
});

test('non-pdf documents are rejected', function () {
    Storage::fake('public');
    $user = User::factory()->cleaner()->create();
    Sanctum::actingAs($user);

    $response = $this->post('/api/v1/profile', [
        '_method' => 'PATCH',
        'documents' => [UploadedFile::fake()->create('notes.docx', 50, 'application/msword')],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('documents.0');
});

test('an employer updates their own profile', function () {
    Storage::fake('public');
    $user = User::factory()->employer()->create();
    Sanctum::actingAs($user);

    $response = $this->post('/api/v1/profile', [
        '_method' => 'PATCH',
        'employer_type' => 'company',
        'contact_person_name' => 'Maria Reyes',
        'contact_person_contact' => 'maria@acme.test',
        'about' => 'Commercial cleaning contractor.',
    ]);

    $response->assertOk()
        ->assertJsonPath('employer_type', 'company')
        ->assertJsonPath('contact_person_name', 'Maria Reyes');
});

test('moderators cannot update a profile', function () {
    Sanctum::actingAs(User::factory()->moderator()->create());

    $this->postJson('/api/v1/profile', ['_method' => 'PATCH', 'bio' => 'x'])
        ->assertForbidden();
});
