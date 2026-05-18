<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('application_status_logs')) {
            return;
        }

        Schema::create('application_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('building_plan_applications')->cascadeOnDelete();
            $table->unsignedBigInteger('action_by_user_id')->nullable();
            $table->string('action_by_role', 80)->nullable();
            $table->string('old_status', 80)->nullable();
            $table->string('new_status', 80)->index();
            $table->text('remarks')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_status_logs');
    }
};
