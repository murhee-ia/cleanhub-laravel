<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_job_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cleaning_job_category_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description');
            $table->text('requirements')->nullable();
            $table->text('qualifications')->nullable();
            $table->string('country')->index();
            $table->string('city')->index();
            $table->string('address')->nullable();
            $table->date('schedule_date')->index();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedSmallInteger('cleaners_needed')->default(1);
            $table->date('application_deadline')->nullable();
            $table->string('visibility')->default('draft')->index();
            $table->string('status')->default('open')->index();
            $table->decimal('pay_amount', 10, 2)->nullable()->index();
            $table->char('pay_currency', 3)->nullable();
            $table->json('media')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_job_posts');
    }
};
