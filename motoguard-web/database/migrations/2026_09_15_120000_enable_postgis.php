<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis WITH SCHEMA extensions');

        if (DB::scalar("select to_regtype('geography') is null")) {
            throw new RuntimeException('PostGIS is installed, but the "extensions" schema is not on the connection search_path.');
        }
    }
};
