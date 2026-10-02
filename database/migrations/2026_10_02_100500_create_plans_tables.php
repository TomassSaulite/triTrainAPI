<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->string('status', 16);
            $table->unsignedInteger('version')->default(1);
            $table->string('generator_version', 16);
            $table->decimal('starting_ctl', 5, 1);
            $table->decimal('target_ctl', 5, 1);
            $table->json('warnings')->nullable();
            $table->timestamps();

            $table->index(['athlete_id', 'status']);
        });

        Schema::create('plan_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8);
            $table->date('start_date');
            $table->date('end_date');
        });

        Schema::create('plan_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_phase_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('week_index');
            $table->date('start_date');
            $table->decimal('target_tss', 6, 1);
            $table->decimal('target_hours', 4, 1);
            $table->boolean('is_recovery')->default(false);

            $table->unique(['plan_id', 'week_index']);
        });

        Schema::create('planned_workouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_week_id')->constrained()->cascadeOnDelete();
            // A brick is a parent row whose bike and run halves are children.
            $table->foreignId('parent_id')->nullable()->constrained('planned_workouts')->cascadeOnDelete();
            $table->foreignId('workout_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('sport', 8);
            $table->string('kind', 16);
            $table->boolean('is_key')->default(false);
            $table->string('title');
            $table->unsignedInteger('target_duration_s');
            $table->unsignedInteger('target_distance_m')->nullable();
            $table->decimal('target_tss', 6, 1);
            $table->json('structure')->nullable();
            $table->string('status', 16);
            $table->decimal('compliance', 4, 2)->nullable();
            $table->timestamps();

            $table->index(['plan_id', 'date']);
            $table->index('activity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_workouts');
        Schema::dropIfExists('plan_weeks');
        Schema::dropIfExists('plan_phases');
        Schema::dropIfExists('plans');
    }
};
