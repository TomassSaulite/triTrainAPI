<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_templates', function (Blueprint $table) {
            $table->id();
            // Null athlete_id marks the shared system library; athletes can add their own.
            $table->foreignId('athlete_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('slug', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('sport', 8);
            $table->string('kind', 16);
            $table->json('phases');
            $table->unsignedInteger('min_s');
            $table->unsignedInteger('max_s');
            $table->decimal('intensity_factor', 4, 3);
            $table->json('structure');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['athlete_id', 'slug']);
            $table->index(['sport', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_templates');
    }
};
