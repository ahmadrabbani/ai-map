<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_expert_marking_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cad_expert_marking_id')->constrained('cad_expert_markings')->cascadeOnDelete();
            $table->json('old_points_json')->nullable();
            $table->json('old_measurement_json')->nullable();
            $table->string('changed_by')->nullable();
            $table->string('change_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_expert_marking_revisions');
    }
};
