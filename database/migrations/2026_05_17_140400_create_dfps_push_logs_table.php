<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dfps_push_logs')) {
            return;
        }

        Schema::create('dfps_push_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('building_plan_applications')->cascadeOnDelete();
            $table->unsignedBigInteger('pushed_by_user_id')->nullable();
            $table->string('endpoint_url')->nullable();
            $table->json('request_payload_json')->nullable();
            $table->string('zip_file_path')->nullable();
            $table->integer('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->boolean('success')->default(false)->index();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dfps_push_logs');
    }
};
