<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Training days are the athlete's days: a 06:00 run in Riga is 03:00 UTC,
     * and an evening run in Los Angeles is already tomorrow in UTC.
     */
    public function up(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->string('timezone', 64)->default('UTC')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
