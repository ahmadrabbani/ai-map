<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_entity_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('map_drawing_id')->constrained('map_drawings')->cascadeOnDelete();
            $table->string('semantic_entity')->index();
            $table->string('entity_handle')->index();
            $table->string('mapping_source')->default('auto');
            $table->decimal('confidence_score', 6, 2)->nullable();
            $table->string('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_entity_mappings');
    }
};

