<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('building_plan_applications', function (Blueprint $table) {
            if (!Schema::hasColumn('building_plan_applications', 'scheme_id')) {
                $table->string('scheme_id', 120)->nullable()->after('scheme');
            }
            if (!Schema::hasColumn('building_plan_applications', 'scheme_name')) {
                $table->string('scheme_name', 255)->nullable()->after('scheme_id');
            }
            if (!Schema::hasColumn('building_plan_applications', 'block_id')) {
                $table->string('block_id', 120)->nullable()->after('block');
            }
            if (!Schema::hasColumn('building_plan_applications', 'block_name')) {
                $table->string('block_name', 255)->nullable()->after('block_id');
            }
            if (!Schema::hasColumn('building_plan_applications', 'plot_no')) {
                $table->string('plot_no', 120)->nullable()->after('plot_ref');
            }
            if (!Schema::hasColumn('building_plan_applications', 'plot_area')) {
                $table->decimal('plot_area', 12, 3)->nullable()->after('plot_no');
            }
            if (!Schema::hasColumn('building_plan_applications', 'plot_address')) {
                $table->string('plot_address', 500)->nullable()->after('selected_address');
            }
            if (!Schema::hasColumn('building_plan_applications', 'current_status')) {
                $table->string('current_status', 80)->nullable()->after('status')->index();
            }
            if (!Schema::hasColumn('building_plan_applications', 'ai_report_path')) {
                $table->string('ai_report_path')->nullable()->after('ai_report_json');
            }
            if (!Schema::hasColumn('building_plan_applications', 'cad_file_path')) {
                $table->string('cad_file_path')->nullable()->after('plan_file_path');
            }
            if (!Schema::hasColumn('building_plan_applications', 'legacy_bp_application_id')) {
                $table->unsignedBigInteger('legacy_bp_application_id')->nullable()->after('application_no')->index();
            }
            if (!Schema::hasColumn('building_plan_applications', 'ad_epermit_decision')) {
                $table->string('ad_epermit_decision', 80)->nullable()->after('routed_to');
            }
            if (!Schema::hasColumn('building_plan_applications', 'ad_epermit_remarks')) {
                $table->text('ad_epermit_remarks')->nullable()->after('ad_epermit_decision');
            }
            if (!Schema::hasColumn('building_plan_applications', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('building_plan_applications', function (Blueprint $table) {
            foreach ([
                'scheme_id', 'scheme_name', 'block_id', 'block_name', 'plot_no', 'plot_area', 'plot_address',
                'current_status', 'ai_report_path', 'cad_file_path', 'legacy_bp_application_id', 'ad_epermit_decision',
                'ad_epermit_remarks', 'reviewed_at'
            ] as $col) {
                if (Schema::hasColumn('building_plan_applications', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
