<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CleaningJobCategoryResource;
use App\Models\CleaningJobCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CleaningJobCategoryController extends Controller
{
    /**
     * List the active cleaning categories cleaners choose from and job
     * browsing filters by. Guest-accessible.
     */
    public function index(): AnonymousResourceCollection
    {
        $categories = CleaningJobCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return CleaningJobCategoryResource::collection($categories);
    }
}
