<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cad_submission_id')->constrained('cad_submissions')->cascadeOnDelete();
            $table->string('handle');
            $table->string('layer_name')->nullable()->index();
            $table->string('normalized_layer_name')->nullable()->index();
            $table->string('entity_type')->nullable()->index();
            $table->string('geometry_type')->nullable()->index();
            $table->json('geometry_json')->nullable();
            $table->json('bbox_json')->nullable();
            $table->json('measurement_json')->nullable();
            $table->text('text_content')->nullable();
            $table->timestamps();

            $table->unique(['cad_submission_id', 'handle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_entities');
    }
};
