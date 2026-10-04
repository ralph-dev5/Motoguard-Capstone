<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Devices no longer have a name of their own: each is known by the ID built into its board.
     * The name column stays (alerts, SMS and the map label read it) and simply holds that ID.
     */
    public function up(): void
    {
        DB::table('devices')->update(['name' => DB::raw('serial')]);
    }

    public function down(): void
    {
        // The old names are not kept anywhere, so there is nothing to restore.
    }
};
