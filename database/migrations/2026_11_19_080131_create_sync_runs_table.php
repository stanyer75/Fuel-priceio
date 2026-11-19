<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An audit trail of every sync against Fuel Finder: what was asked for,
     * how many requests it took, what was rejected or repaired, and why a
     * run failed. Shown at /status and /api/status.
     */
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);          // stations | prices_full | prices_incremental
            $table->string('source', 16);        // live | fixture
            $table->string('status', 16);        // running | succeeded | failed
            $table->timestamp('changed_since')->nullable();
            $table->unsignedInteger('requests_made')->default(0);
            $table->unsignedInteger('batches')->default(0);
            $table->unsignedInteger('records_received')->default(0);
            $table->unsignedInteger('rows_saved')->default(0);
            $table->unsignedInteger('rows_rejected')->default(0);
            $table->json('rejections')->nullable();   // {"invalid_coordinates": 2, ...}
            $table->json('corrections')->nullable();  // {"coordinates_swapped": 3, ...}
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
