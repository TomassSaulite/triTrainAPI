<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A missed key session stays in history as missed; the adaptation loop
     * schedules a copy later in the week that points back at it.
     */
    public function up(): void
    {
        Schema::table('planned_workouts', function (Blueprint $table) {
            $table->foreignId('rescheduled_from_id')->nullable()->after('parent_id')
                ->constrained('planned_workouts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('planned_workouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rescheduled_from_id');
        });
    }
};
