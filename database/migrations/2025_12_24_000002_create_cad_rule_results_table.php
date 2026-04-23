<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_rule_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cad_submission_id')
                ->constrained('cad_submissions')
                ->onDelete('cascade');
            $table->string('rule_id');
            $table->string('rule_type')->nullable();
            $table->string('title')->nullable();
            $table->string('required_value')->nullable();
            $table->string('measured_value')->nullable();
            $table->string('unit')->nullable();
            $table->string('operator')->nullable();
            $table->boolean('is_compliant')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_rule_results');
    }
};
