<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How the device last reached the server: over WiFi (with the network's name) or over the
     * A7670G's own 4G mobile data, which it falls back to when WiFi is out of reach.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('network', 16)->nullable()->after('battery_voltage');
            $table->string('network_name', 64)->nullable()->after('network');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['network', 'network_name']);
        });
    }
};
