<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_prices', function (Blueprint $table) {
            $table->id();
            // Deliberately no foreign key: price and station endpoints are
            // synced separately, so a price can arrive before its station.
            $table->string('node_id', 64);
            $table->string('fuel_type', 16);
            $table->decimal('price_ppl', 5, 1);           // pence per litre
            $table->timestamp('price_updated_at')->nullable(); // when the price last CHANGED (UTC)
            $table->json('data_corrections')->nullable();
            $table->timestamps();                           // updated_at = when WE last saw it

            // Also absorbs duplicate rows the feed sometimes sends.
            $table->unique(['node_id', 'fuel_type']);
            $table->index('fuel_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_prices');
    }
};
