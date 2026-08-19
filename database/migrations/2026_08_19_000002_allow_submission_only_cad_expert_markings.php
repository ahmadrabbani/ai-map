<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cad_expert_markings', function (Blueprint $table) {
            $table->unsignedBigInteger('cad_approval_application_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Do not restore NOT NULL: submission-only learning examples may exist.
    }
};
