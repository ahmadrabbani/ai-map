<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cad_rule_results', function (Blueprint $table) {
            if (! Schema::hasColumn('cad_rule_results', 'system_measured_value')) {
                $table->string('system_measured_value')->nullable()->after('measured_value');
            }
            if (! Schema::hasColumn('cad_rule_results', 'measurement_source')) {
                $table->string('measurement_source')->nullable()->after('source');
            }
            if (! Schema::hasColumn('cad_rule_results', 'measurement_points_json')) {
                $table->json('measurement_points_json')->nullable()->after('details');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cad_rule_results', function (Blueprint $table) {
            if (Schema::hasColumn('cad_rule_results', 'measurement_points_json')) {
                $table->dropColumn('measurement_points_json');
            }
            if (Schema::hasColumn('cad_rule_results', 'measurement_source')) {
                $table->dropColumn('measurement_source');
            }
            if (Schema::hasColumn('cad_rule_results', 'system_measured_value')) {
                $table->dropColumn('system_measured_value');
            }
        });
    }
};
