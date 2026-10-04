<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bluetooth owner detection. The owner's phone broadcasts a beacon (an iBeacon UUID, or a
     * fixed Bluetooth address for a key-fob tag); while the ESP32 hears it, motion alarms are
     * held off so the owner using their own motorcycle never sets it off.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('owner_beacon', 36)->nullable()->after('owner_phone');
            $table->boolean('owner_nearby')->default(false)->after('owner_beacon');
            $table->timestamp('owner_seen_at')->nullable()->after('owner_nearby');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['owner_beacon', 'owner_nearby', 'owner_seen_at']);
        });
    }
};
