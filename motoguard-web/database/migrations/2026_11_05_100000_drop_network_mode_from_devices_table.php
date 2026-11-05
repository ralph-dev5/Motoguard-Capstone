<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The device picks its network by itself again (home WiFi, then the A7670G's 4G data when WiFi
     * is out of range), so the owner's manual choice is gone.
     */
    public function up(): void
    {
        if (Schema::hasColumn('devices', 'network_mode')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->dropColumn('network_mode');
            });
        }
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('network_mode', 16)->default('auto')->after('network_name');
        });
    }
};
