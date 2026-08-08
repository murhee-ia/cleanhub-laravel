<?php

use App\Enums\JobPostStatus;
use App\Models\AuditLog;
use App\Models\CleaningJobCategory;
use App\Models\CleaningJobPost;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('an admin can list every job post regardless of status', function () {
    CleaningJobPost::factory()->create(['status' => JobPostStatus::Open]);
    CleaningJobPost::factory()->create(['status' => JobPostStatus::Removed]);
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/v1/admin/jobs')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/admin/jobs?status=removed')->assertOk()->assertJsonCount(1, 'data');
});

test('an admin can hide and unhide a job post', function () {
    $post = CleaningJobPost::factory()->create(['status' => JobPostStatus::Open]);
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->patchJson("/api/v1/admin/jobs/{$post->id}/hide")
        ->assertOk()
        ->assertJsonPath('status', 'removed');
    expect($post->fresh()->status)->toBe(JobPostStatus::Removed);
    expect(AuditLog::where('action', 'content.hidden')->exists())->toBeTrue();

    $this->patchJson("/api/v1/admin/jobs/{$post->id}/unhide")
        ->assertOk()
        ->assertJsonPath('status', 'open');
    expect($post->fresh()->status)->toBe(JobPostStatus::Open);
});

test('an admin can list all categories including inactive ones', function () {
    CleaningJobCategory::factory()->create(['is_active' => true]);
    CleaningJobCategory::factory()->create(['is_active' => false]);
    Sanctum::actingAs(User::factory()->admin()->create());

    // Unpaginated — a bare array, matching the public categories endpoint.
    $this->getJson('/api/v1/admin/categories')->assertOk()->assertJsonCount(2);
});

test('an admin can create a category and the slug is derived from the name', function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->postJson('/api/v1/admin/categories', ['name' => 'Data Centre'])
        ->assertCreated()
        ->assertJsonPath('slug', 'data-centre')
        ->assertJsonPath('is_active', true);
    expect(CleaningJobCategory::where('slug', 'data-centre')->exists())->toBeTrue();
    expect(AuditLog::where('action', 'category.created')->exists())->toBeTrue();
});

test('an admin can retire a category by toggling it inactive', function () {
    $category = CleaningJobCategory::factory()->create(['is_active' => true]);
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->patchJson("/api/v1/admin/categories/{$category->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('is_active', false);
    expect($category->fresh()->is_active)->toBeFalse();
});

test('non-admins cannot manage content', function () {
    $category = CleaningJobCategory::factory()->create();
    Sanctum::actingAs(User::factory()->moderator()->create());

    $this->getJson('/api/v1/admin/jobs')->assertForbidden();
    $this->postJson('/api/v1/admin/categories', ['name' => 'X'])->assertForbidden();
    $this->patchJson("/api/v1/admin/categories/{$category->id}", ['is_active' => false])->assertForbidden();
});
