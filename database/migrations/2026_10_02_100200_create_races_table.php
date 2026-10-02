<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('races', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('distance', 16);
            $table->date('date');
            $table->string('priority', 1);
            $table->timestamps();

            $table->index(['athlete_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('races');
    }
};
