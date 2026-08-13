<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('application_site_reviews')) {
            return;
        }

        Schema::create('application_site_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('building_plan_applications')->cascadeOnDelete();
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('site_condition', 40)->nullable();
            $table->boolean('front_road_detected')->nullable();
            $table->boolean('side_road_detected')->nullable();
            $table->boolean('corner_plot')->nullable();
            $table->text('remarks')->nullable();
            $table->json('site_review_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_site_reviews');
    }
};
