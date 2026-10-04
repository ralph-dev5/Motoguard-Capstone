<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The owner's phone hotspot as a second WiFi: away from home the device switches to it by
     * itself. The password is stored encrypted (see the Device casts), hence the wide column.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('hotspot_ssid', 32)->nullable()->after('owner_beacon');
            $table->text('hotspot_password')->nullable()->after('hotspot_ssid');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['hotspot_ssid', 'hotspot_password']);
        });
    }
};
