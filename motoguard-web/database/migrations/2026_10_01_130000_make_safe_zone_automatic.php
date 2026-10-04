<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The safe zone stops being a circle the owner draws by hand and becomes the parking spot
     * itself: its center is set automatically where the motorcycle was when it was armed, and
     * cleared when it is disarmed. safe_zone_radius_m stays as the owner's chosen radius.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->timestamp('parked_at')->nullable()->after('safe_zone_radius_m');
        });

        // Hand-drawn centers mean nothing under the new rule; the next arming sets a real one.
        DB::table('devices')->update(['safe_zone_center' => null]);
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('parked_at');
        });
    }
};
