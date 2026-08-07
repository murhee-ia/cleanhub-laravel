<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewee_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('stars');
            $table->text('text')->nullable();
            $table->string('status')->default('visible');
            $table->timestamps();

            // Both directions of a completed application (cleaner→employer and
            // employer→cleaner) are separate rows, so the unique key is on the
            // reviewer, not the application alone.
            $table->unique(['application_id', 'reviewer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
