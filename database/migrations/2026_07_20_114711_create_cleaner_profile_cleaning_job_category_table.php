<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaner_profile_cleaning_job_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cleaner_profile_id')
                ->constrained(indexName: 'cpcjc_profile_fk')
                ->cascadeOnDelete();
            $table->foreignId('cleaning_job_category_id')
                ->constrained(indexName: 'cpcjc_category_fk')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['cleaner_profile_id', 'cleaning_job_category_id'], 'cpcjc_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaner_profile_cleaning_job_category');
    }
};
