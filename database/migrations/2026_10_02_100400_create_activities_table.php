<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('external_id')->nullable();
            $table->string('sport', 8);
            $table->string('name')->nullable();
            $table->dateTime('started_at');
            $table->unsignedInteger('duration_s');
            $table->unsignedInteger('distance_m')->nullable();
            $table->unsignedSmallInteger('avg_hr')->nullable();
            $table->unsignedSmallInteger('np_w')->nullable();
            $table->unsignedSmallInteger('best_20min_power_w')->nullable();
            // Seconds per km for runs, seconds per 100 m for swims.
            $table->decimal('avg_pace', 7, 2)->nullable();
            $table->decimal('tss', 6, 1)->nullable();
            $table->string('tss_method', 16)->nullable();
            $table->decimal('intensity_factor', 4, 3)->nullable();
            $table->string('fit_path')->nullable();
            $table->timestamps();

            $table->unique(['athlete_id', 'source', 'external_id']);
            $table->index(['athlete_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
