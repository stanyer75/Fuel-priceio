<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            // Fuel Finder's SHA-256 identifier. Trading name + town is NOT unique.
            $table->string('node_id', 64)->unique();
            $table->string('trading_name');
            $table->string('brand_name')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('county')->nullable();
            $table->string('postcode', 10)->nullable();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->boolean('is_motorway_service_station')->default(false);
            $table->boolean('is_supermarket_service_station')->default(false);
            $table->boolean('temporary_closure')->default(false);
            $table->boolean('permanent_closure')->default(false);
            $table->json('opening_times')->nullable();
            $table->json('amenities')->nullable();
            // Which normaliser fixes were applied (e.g. coordinates_swapped).
            $table->json('data_corrections')->nullable();
            $table->timestamps();

            // Bounding-box prefilter for the route corridor search.
            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stations');
    }
};
