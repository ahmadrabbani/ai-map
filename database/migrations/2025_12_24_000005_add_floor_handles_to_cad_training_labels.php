<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cad_training_labels', function (Blueprint $table) {
            // Multi-storey: expert can map one polygon handle per floor.
            // Example JSON: {"ground":"1A2B", "first":"1A2C", "second":"1A2D"}
            $table->json('floor_handles')->nullable()->after('building_footprint_handle');
        });
    }

    public function down(): void
    {
        Schema::table('cad_training_labels', function (Blueprint $table) {
            $table->dropColumn('floor_handles');
        });
    }
};
