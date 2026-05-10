<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_expert_markings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cad_approval_application_id')
                ->constrained('cad_approval_applications')
                ->cascadeOnDelete();
            $table->foreignId('cad_approval_plan_id')
                ->nullable()
                ->constrained('cad_approval_plans')
                ->nullOnDelete();
            $table->string('floor_type')->nullable();
            $table->string('marking_type');
            $table->json('geometry_json')->nullable();
            $table->decimal('measured_area', 14, 3)->nullable();
            $table->decimal('measured_length', 14, 3)->nullable();
            $table->text('remarks')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_expert_markings');
    }
};
