<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('availability_overrides', function (Blueprint $table) {
            // Only easy sessions on this day, e.g. the first days back after being sick.
            $table->boolean('easy_only')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('availability_overrides', function (Blueprint $table) {
            $table->dropColumn('easy_only');
        });
    }
};
