<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A plan is training history: deleting its race archives it instead of
     * deleting every workout, compliance record and revision with it.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropForeign(['race_id']);
            $table->foreignId('race_id')->nullable()->change();
            $table->foreign('race_id')->references('id')->on('races')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropForeign(['race_id']);
            $table->foreignId('race_id')->nullable(false)->change();
            $table->foreign('race_id')->references('id')->on('races')->cascadeOnDelete();
        });
    }
};
