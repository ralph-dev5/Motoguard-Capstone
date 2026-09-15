<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('plate_number')->nullable();
            $table->string('serial')->unique();
            $table->string('owner_phone')->nullable();
            $table->boolean('is_armed')->default(true);
            $table->string('status')->default('offline');
            $table->decimal('battery_voltage', 5, 2)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->geography('last_location', subtype: 'point', srid: 4326)->nullable();
            $table->geography('safe_zone_center', subtype: 'point', srid: 4326)->nullable();
            $table->unsignedInteger('safe_zone_radius_m')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
