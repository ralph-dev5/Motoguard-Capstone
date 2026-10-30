<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An owner may add several MotoGuard+ units again (one per motorcycle), so the one-device rule
 * from 2026_10_21_120000 is lifted. Each device still belongs to exactly one account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->unique('user_id');
        });
    }
};
