<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cleaning_job_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->text('message')->nullable();
            $table->string('resume_path')->nullable();
            $table->text('private_note')->nullable();
            // Unlike private_note, this one is written for the cleaner to read.
            $table->text('decision_message')->nullable();
            $table->timestamps();

            // Withdrawing or being rejected only transitions the status, never
            // deletes the row, so this constraint is what permanently blocks a
            // second application to the same job.
            $table->unique(['cleaning_job_post_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
