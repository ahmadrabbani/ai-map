<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cad_expert_markings', function (Blueprint $table) {
            $table->string('snapshot_path')->nullable()->after('measurement_json');
            $table->json('selected_handles_json')->nullable()->after('snapshot_path');
            $table->json('facts_json')->nullable()->after('selected_handles_json');
            $table->string('rule_code')->nullable()->index()->after('facts_json');
            $table->string('compliance_status')->nullable()->index()->after('rule_code');
        });
    }

    public function down(): void
    {
        Schema::table('cad_expert_markings', function (Blueprint $table) {
            $table->dropColumn(['snapshot_path', 'selected_handles_json', 'facts_json', 'rule_code', 'compliance_status']);
        });
    }
};
