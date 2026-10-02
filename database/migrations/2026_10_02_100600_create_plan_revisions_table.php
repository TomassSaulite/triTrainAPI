<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('reason', 16);
            $table->string('summary');
            $table->json('changes');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['plan_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_revisions');
    }
};
