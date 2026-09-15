<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->geography('location', subtype: 'point', srid: 4326);
            $table->decimal('speed_kmh', 6, 2)->nullable();
            $table->decimal('heading', 5, 2)->nullable();
            $table->unsignedSmallInteger('satellites')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['device_id', 'recorded_at']);
            $table->spatialIndex('location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_logs');
    }
};
