<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('bp_epermit_sync_logs')) {
            return;
        }

        Schema::create('bp_epermit_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bp_application_id')->nullable()->constrained('bp_applications')->nullOnDelete();
            $table->string('sync_type', 60)->default('case_submit');
            $table->string('endpoint', 1024)->nullable();
            $table->json('request_payload_json')->nullable();
            $table->unsignedInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->boolean('is_success')->default(false);
            $table->string('external_case_id', 120)->nullable();
            $table->string('external_application_no', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['bp_application_id', 'is_success']);
            $table->index(['sync_type', 'created_at']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bp_epermit_sync_logs')) {
            return;
        }

        Schema::dropIfExists('bp_epermit_sync_logs');
    }
};
