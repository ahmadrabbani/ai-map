<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_entity_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cad_submission_id')->constrained('cad_submissions')->cascadeOnDelete();

            // DXF identity
            $table->string('entity_handle')->index();
            $table->string('entity_type')->index(); // LWPOLYLINE / POLYLINE / LINE / INSERT ...
            $table->string('layer')->nullable()->index();

            // Geometry / features
            $table->boolean('is_closed')->default(false);
            $table->unsignedInteger('num_vertices')->default(0);
            $table->decimal('area', 18, 6)->nullable();
            $table->decimal('bbox_x0', 18, 6)->nullable();
            $table->decimal('bbox_y0', 18, 6)->nullable();
            $table->decimal('bbox_x1', 18, 6)->nullable();
            $table->decimal('bbox_y1', 18, 6)->nullable();
            $table->decimal('bbox_w', 18, 6)->nullable();
            $table->decimal('bbox_h', 18, 6)->nullable();
            $table->decimal('rectangularity', 18, 8)->nullable(); // area/(bbox_w*bbox_h)
            $table->decimal('centroid_x', 18, 6)->nullable();
            $table->decimal('centroid_y', 18, 6)->nullable();

            // Optional: store simplified points for UI/overlay/debugging
            $table->json('points_xy')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_entity_features');
    }
};
