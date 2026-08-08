<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Http\Resources\AdminCategoryResource;
use App\Models\AuditLog;
use App\Models\CleaningJobCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * Category management. There is no hard delete — a job post's foreign key
 * restricts dropping a category in use, and retiring one is better expressed
 * as toggling `is_active` off, which hides it from pickers while leaving
 * existing posts intact and the category reactivatable later.
 */
class CategoryController extends Controller
{
    /**
     * All categories, active and retired, so the admin can manage both. Unlike
     * the public listing this is not filtered to active.
     */
    public function index(): AnonymousResourceCollection
    {
        return AdminCategoryResource::collection(
            CleaningJobCategory::query()->orderBy('name')->get(),
        );
    }

    public function store(StoreCategoryRequest $request): AdminCategoryResource
    {
        $category = CleaningJobCategory::create([
            'name' => $request->validated('name'),
            'slug' => $request->validated('slug') ?? Str::slug($request->validated('name')),
            'is_active' => $request->boolean('is_active', true),
        ]);

        AuditLog::record($request->user(), 'category.created', null, [
            'category_id' => $category->id,
            'name' => $category->name,
        ]);

        return new AdminCategoryResource($category);
    }

    public function update(UpdateCategoryRequest $request, CleaningJobCategory $category): AdminCategoryResource
    {
        $category->update($request->validated());

        AuditLog::record($request->user(), 'category.updated', null, [
            'category_id' => $category->id,
            'changes' => $request->validated(),
        ]);

        return new AdminCategoryResource($category);
    }
}
