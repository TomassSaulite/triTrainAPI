<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_load', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('tss', 6, 1);
            $table->decimal('ctl', 5, 1);
            $table->decimal('atl', 5, 1);
            $table->decimal('tsb', 5, 1);

            $table->unique(['athlete_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_load');
    }
};
