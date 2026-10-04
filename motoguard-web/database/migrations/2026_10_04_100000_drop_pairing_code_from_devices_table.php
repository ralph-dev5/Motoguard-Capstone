<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pairing codes are gone: a board now identifies itself by the ID built into its chip (the
     * serial column) and enrolls by itself once its owner has added that ID.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique(['pairing_code']);
            $table->dropColumn(['pairing_code', 'pairing_code_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('pairing_code', 6)->nullable()->unique()->after('serial');
            $table->timestamp('pairing_code_expires_at')->nullable()->after('pairing_code');
        });
    }
};
