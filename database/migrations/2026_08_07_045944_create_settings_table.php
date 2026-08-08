<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A deliberately small key/value store for platform limits (max file
        // size, per-cleaner application cap, report cap, ...). One row per
        // setting, value kept as text and cast per key in the model — enough
        // without a column-per-setting schema that a migration would have to
        // chase every time a limit is added.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
