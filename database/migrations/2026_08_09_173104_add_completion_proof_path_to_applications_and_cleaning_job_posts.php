<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add completion proof paths to both tables in one migration:
 *   - applications.completion_proof_path        → cleaner's proof file
 *   - cleaning_job_posts.completion_proof_path  → employer's proof file
 *
 * Both are nullable so existing rows need no back-fill; they only become
 * required at the point of marking each side completed (enforced in the
 * controller, not the schema).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->string('completion_proof_path')->nullable()->after('resume_path');
        });

        Schema::table('cleaning_job_posts', function (Blueprint $table): void {
            $table->string('completion_proof_path')->nullable()->after('media');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn('completion_proof_path');
        });

        Schema::table('cleaning_job_posts', function (Blueprint $table): void {
            $table->dropColumn('completion_proof_path');
        });
    }
};

