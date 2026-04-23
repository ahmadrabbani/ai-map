<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cad_expert_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cad_submission_id')->constrained('cad_submissions')->onDelete('cascade');
            // layer-level labeling (fastest for domain experts)
            $table->string('plot_layer')->nullable();
            $table->string('building_layer')->nullable();
            $table->string('dimension_layer')->nullable();
            $table->string('text_layer')->nullable();

            // entity-level labeling (more precise; optional)
            $table->string('plot_entity_handle')->nullable();
            $table->string('building_entity_handle')->nullable();

            // orientation label for setbacks
            $table->enum('front_side', ['auto','north','south','east','west'])->default('auto');

            $table->text('notes')->nullable();
            $table->string('labeled_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_expert_labels');
    }
};
