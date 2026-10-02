<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thresholds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->string('sport', 8)->nullable();
            $table->string('metric', 32);
            $table->decimal('value', 8, 2);
            $table->date('tested_at');
            $table->string('source', 16);
            $table->timestamps();

            $table->index(['athlete_id', 'metric', 'tested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thresholds');
    }
};
