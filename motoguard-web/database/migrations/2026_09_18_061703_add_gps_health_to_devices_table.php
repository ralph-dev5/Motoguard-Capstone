<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Health of the GPS receiver, so the dashboard can tell a module that is not wired from one
     * that is simply still acquiring. A position alone cannot distinguish them: both look like
     * "no location", which is exactly the ambiguity that cost time on the bench.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Null means the firmware predates GPS reporting; 0 means it reported nothing arriving.
            $table->unsignedBigInteger('gps_chars')->nullable()->after('battery_voltage');
            $table->unsignedSmallInteger('gps_satellites')->nullable()->after('gps_chars');
            // Separate from last_location, which is never cleared and so cannot say "fix is current".
            $table->timestamp('gps_fix_at')->nullable()->after('gps_satellites');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['gps_chars', 'gps_satellites', 'gps_fix_at']);
        });
    }
};
