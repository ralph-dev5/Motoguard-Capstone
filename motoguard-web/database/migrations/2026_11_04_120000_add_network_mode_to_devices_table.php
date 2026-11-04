<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which network the owner wants the device to use: auto (WiFi first, the A7670G's 4G data when
     * WiFi is lost), wifi (never mobile data) or cellular (mobile data even with WiFi in range).
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('network_mode', 16)->default('auto')->after('network_name');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('network_mode');
        });
    }
};
