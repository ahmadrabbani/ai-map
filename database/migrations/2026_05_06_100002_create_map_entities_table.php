<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('map_drawing_id')->constrained('map_drawings')->cascadeOnDelete();
            $table->string('handle');
            $table->string('layer_name')->index();
            $table->string('entity_type');
            $table->string('semantic_entity')->nullable()->index();
            $table->string('processing_role')->nullable();
            $table->json('geometry_json')->nullable();
            $table->json('bbox_json')->nullable();
            $table->decimal('area', 18, 4)->nullable();
            $table->decimal('perimeter', 18, 4)->nullable();
            $table->boolean('is_closed')->default(false);
            $table->decimal('confidence_score', 6, 2)->nullable();
            $table->string('mapping_status')->default('unmapped');
            $table->string('mapping_source')->nullable();
            $table->boolean('is_ignored')->default(false);
            $table->timestamps();

            $table->unique(['map_drawing_id', 'handle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_entities');
    }
};

