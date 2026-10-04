<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Short-lived pairing code. The owner types six characters into the device's WiFi setup page
     * instead of a 48-character token; the device trades the code for its token on first contact.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('pairing_code', 6)->nullable()->unique()->after('serial');
            $table->timestamp('pairing_code_expires_at')->nullable()->after('pairing_code');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique(['pairing_code']);
            $table->dropColumn(['pairing_code', 'pairing_code_expires_at']);
        });
    }
};
