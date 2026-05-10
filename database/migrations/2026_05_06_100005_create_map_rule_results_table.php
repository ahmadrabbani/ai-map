<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_rule_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('map_drawing_id')->constrained('map_drawings')->cascadeOnDelete();
            $table->string('rule_code')->index();
            $table->string('rule_title')->nullable();
            $table->string('required_value')->nullable();
            $table->string('actual_value')->nullable();
            $table->string('unit')->nullable();
            $table->string('status')->default('needs_review');
            $table->text('message')->nullable();
            $table->json('source_layers_json')->nullable();
            $table->json('source_entities_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_rule_results');
    }
};

