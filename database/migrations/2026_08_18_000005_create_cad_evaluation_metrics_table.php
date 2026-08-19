<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('cad_evaluation_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_run_id')->constrained('cad_evaluation_runs')->cascadeOnDelete();
            $table->string('metric_scope')->default('entity');
            $table->string('entity_type')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('cad_evaluation_metrics');
    }
};
