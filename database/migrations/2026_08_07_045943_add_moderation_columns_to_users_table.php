<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Suspension is a reversible pause on an account (reactivate clears
            // it), kept separate from deletion so the two admin actions never
            // collide. A suspended user stays in every normal query — only
            // their ability to authenticate is blocked.
            $table->timestamp('suspended_at')->nullable()->after('email_verified_at');

            // Soft delete so removing a user is recoverable, per the platform
            // rule to never hard-delete admin-facing records.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('suspended_at');
            $table->dropSoftDeletes();
        });
    }
};
