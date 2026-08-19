<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('cad_evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cad_submission_id')->nullable()->constrained('cad_submissions')->cascadeOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('cad_model_versions')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('dataset_split')->default('gold');
            $table->boolean('locked_ground_truth')->default(false);
            $table->json('params')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('cad_evaluation_runs');
    }
};
