<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the device itself says it is doing, as opposed to is_armed, which is only what the
     * owner asked for on the dashboard. The two differ until the next heartbeat applies a change,
     * and while the owner's phone holds the alarm off. Null until the first heartbeat reports it.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('reported_armed')->nullable()->after('is_armed');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('reported_armed');
        });
    }
};
