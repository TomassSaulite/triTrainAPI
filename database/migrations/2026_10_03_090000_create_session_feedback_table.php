<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->unique()->constrained()->cascadeOnDelete();
            // Overall effort, 1 (very easy) to 10 (maximal).
            $table->unsignedTinyInteger('rpe');
            // How each part felt, 1 (best) to 5 (worst); optional.
            $table->unsignedTinyInteger('muscles')->nullable();
            $table->unsignedTinyInteger('breathing')->nullable();
            $table->unsignedTinyInteger('energy')->nullable();
            $table->unsignedTinyInteger('mood')->nullable();
            $table->boolean('pain')->default(false);
            $table->string('pain_area', 60)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['athlete_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_feedback');
    }
};
