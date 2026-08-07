<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // The moderator/admin who performed the action. Nullable +
            // nullOnDelete so the trail survives even if that account is later
            // removed — an audit log that vanishes with its actor is useless.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action')->index();

            // The optional subject of the action (a user, report, job post,
            // rating, ...) stored polymorphically with the same short morph
            // aliases as reports. Null for actions with no single target
            // (e.g. a settings change).
            $table->nullableMorphs('auditable');

            // Free-form snapshot of what changed (old/new role, reason, ...),
            // enough to reconstruct or reverse the action after the fact.
            $table->json('context')->nullable();

            // Append-only: an audit entry is written once and never updated,
            // so only the creation time is tracked.
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
