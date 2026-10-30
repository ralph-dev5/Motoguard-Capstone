<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The unit now recognises the owner's phone by Bluetooth pairing (no app), so the beacon UUID that
 * a phone app used to broadcast is no longer stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('owner_beacon');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('owner_beacon', 36)->nullable();
        });
    }
};
