<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_geometry_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('map_drawing_id')->constrained('map_drawings')->cascadeOnDelete();
            $table->string('key')->index();
            $table->string('value')->nullable();
            $table->string('unit')->nullable();
            $table->json('source_semantic_entities_json')->nullable();
            $table->string('calculation_method')->nullable();
            $table->string('status')->default('calculated');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_geometry_results');
    }
};

