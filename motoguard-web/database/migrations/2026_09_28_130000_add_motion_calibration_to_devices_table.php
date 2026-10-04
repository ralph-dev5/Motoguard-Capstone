<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Motion calibration: the owner asks for it on the dashboard, the device measures its own
     * resting noise and reports the thresholds it derived. Every MPU clone and every mounting
     * shakes differently, so thresholds measured on the unit beat any number typed into config.h.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->timestamp('calibration_requested_at')->nullable()->after('is_armed');
            $table->timestamp('calibrated_at')->nullable()->after('calibration_requested_at');
            // What the device measured and the thresholds it set from that.
            $table->json('calibration')->nullable()->after('calibrated_at');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['calibration_requested_at', 'calibrated_at', 'calibration']);
        });
    }
};
