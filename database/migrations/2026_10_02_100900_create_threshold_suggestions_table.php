<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threshold_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('metric', 32);
            $table->decimal('current_value', 8, 2)->nullable();
            $table->decimal('suggested_value', 8, 2);
            $table->string('rationale');
            $table->string('status', 16);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['athlete_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threshold_suggestions');
    }
};
