<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_training_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cad_submission_id')->constrained('cad_submissions')->cascadeOnDelete();

            // Expert selections (entity handles from cad_entity_features)
            $table->string('plot_boundary_handle')->nullable();
            $table->string('building_footprint_handle')->nullable();
            // Front side selection relative to plot bbox: 'top','bottom','left','right'
            $table->enum('front_side', ['top', 'bottom', 'left', 'right'])->nullable();

            // Optional: layer mapping chosen by expert (useful when entity handles are not stable)
            $table->json('layer_map')->nullable(); // {"plot":"PLOT","building":"WALL"...}

            $table->text('notes')->nullable();
            $table->string('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_training_labels');
    }
};
