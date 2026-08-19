<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('cad_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cad_submission_id')->constrained('cad_submissions')->cascadeOnDelete();
            // The predictions migration runs immediately after this one, so this
            // is indexed here instead of adding an order-dependent foreign key.
            $table->unsignedBigInteger('cad_prediction_id')->nullable()->index();
            $table->string('label_key')->index();
            $table->string('label_name')->nullable();
            $table->string('geometry_type')->nullable();
            $table->json('geometry_json')->nullable();
            $table->json('attributes')->nullable();
            $table->json('cad_handles')->nullable();
            $table->string('cad_layer')->nullable()->index();
            $table->string('floor')->nullable()->index();
            $table->decimal('width', 16, 4)->nullable();
            $table->decimal('length', 16, 4)->nullable();
            $table->decimal('perimeter', 16, 4)->nullable();
            $table->decimal('area_sq_ft', 16, 4)->nullable();
            $table->decimal('area_sq_m', 16, 4)->nullable();
            $table->boolean('is_closed')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('scale', 16, 8)->nullable();
            $table->boolean('unit_confirmed')->default(false);
            $table->string('status')->default('confirmed')->index();
            $table->string('verification_level')->default('user_correction')->index();
            $table->string('source')->default('manual');
            $table->string('ai_label_key')->nullable();
            $table->decimal('ai_confidence', 6, 5)->nullable();
            $table->string('model_version')->nullable()->index();
            $table->string('dataset_split')->nullable()->index();
            $table->string('drawing_hash')->nullable()->index();
            $table->boolean('locked')->default(false);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('gold_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('gold_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['cad_submission_id', 'cad_prediction_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('cad_tags');
    }
};
