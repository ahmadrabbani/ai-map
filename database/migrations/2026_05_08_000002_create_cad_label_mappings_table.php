<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_label_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cad_submission_id')->constrained('cad_submissions')->cascadeOnDelete();
            $table->string('label_key')->index();
            $table->string('label_name')->nullable();
            $table->foreignId('cad_entity_id')->constrained('cad_entities')->cascadeOnDelete();
            $table->string('cad_handle')->index();
            $table->string('source')->default('manual');
            $table->decimal('confidence', 6, 2)->nullable();
            $table->boolean('user_confirmed')->default(true);
            $table->timestamps();

            $table->unique(['cad_submission_id', 'label_key', 'cad_entity_id'], 'cad_label_unique_label_entity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_label_mappings');
    }
};
