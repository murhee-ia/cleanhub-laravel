<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();

            // Polymorphic target — a user, a job post, or a rating. The type
            // column stores the short morph alias ('user'/'job_post'/'rating')
            // registered in AppServiceProvider, not a PHP class name, so the
            // stored value stays stable even if a model class is renamed later.
            $table->string('reportable_type');
            $table->unsignedBigInteger('reportable_id');
            $table->index(['reportable_type', 'reportable_id']);

            $table->text('reason');
            $table->string('status')->default('open')->index();

            // The moderator/admin who last acted on the report, plus their
            // closing note. Nullable so an untouched report carries neither;
            // nullOnDelete keeps the report readable after its handler is gone.
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            // Speeds up the "does this reporter already have an open report on
            // this target?" duplicate check the controller runs before insert.
            // A partial unique index (open-only) isn't portable to the sqlite
            // used in tests, so the open-case uniqueness is enforced in code.
            $table->index(['reporter_id', 'reportable_type', 'reportable_id'], 'reports_reporter_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
