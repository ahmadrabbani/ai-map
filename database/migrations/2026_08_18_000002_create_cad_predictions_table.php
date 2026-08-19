<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('cad_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cad_submission_id')->constrained('cad_submissions')->cascadeOnDelete();
            $table->string('label_key')->nullable();
            $table->string('label_name')->nullable();
            $table->string('geometry_type')->nullable();
            $table->json('geometry_json')->nullable();
            $table->double('confidence')->nullable();
            $table->string('model_version')->nullable()->index();
            $table->string('cad_handle')->nullable();
            $table->string('cad_layer')->nullable()->index();
            $table->string('floor')->nullable()->index();
            $table->string('status')->default('ai_suggested')->index();
            $table->string('final_label_key')->nullable();
            $table->string('review_action')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['cad_submission_id', 'status', 'confidence']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('cad_predictions');
    }
};
