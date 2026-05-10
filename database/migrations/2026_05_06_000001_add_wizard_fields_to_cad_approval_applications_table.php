<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cad_approval_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('cad_approval_applications', 'identification_number')) {
                $table->string('identification_number')->nullable()->after('applicant_name');
            }
            if (! Schema::hasColumn('cad_approval_applications', 'mobile_number')) {
                $table->string('mobile_number')->nullable()->after('contact_number');
            }
            if (! Schema::hasColumn('cad_approval_applications', 'email')) {
                $table->string('email')->nullable()->after('mobile_number');
            }
            if (! Schema::hasColumn('cad_approval_applications', 'application_type')) {
                $table->string('application_type')->nullable()->after('email');
            }
            if (! Schema::hasColumn('cad_approval_applications', 'property_type')) {
                $table->string('property_type')->nullable()->after('building_type');
            }
            if (! Schema::hasColumn('cad_approval_applications', 'submitted_floors')) {
                $table->json('submitted_floors')->nullable()->after('property_type');
            }
            if (! Schema::hasColumn('cad_approval_applications', 'has_basement')) {
                $table->boolean('has_basement')->default(false)->after('submitted_floors');
            }
            if (! Schema::hasColumn('cad_approval_applications', 'remarks')) {
                $table->text('remarks')->nullable()->after('has_basement');
            }
            if (! Schema::hasColumn('cad_approval_applications', 'verified_data_json')) {
                $table->json('verified_data_json')->nullable()->after('remarks');
            }
            if (! Schema::hasColumn('cad_approval_applications', 'verification_answers_json')) {
                $table->json('verification_answers_json')->nullable()->after('verified_data_json');
            }
            if (! Schema::hasColumn('cad_approval_applications', 'draft_saved_at')) {
                $table->timestamp('draft_saved_at')->nullable()->after('submitted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cad_approval_applications', function (Blueprint $table) {
            $table->dropColumn([
                'identification_number',
                'mobile_number',
                'email',
                'application_type',
                'property_type',
                'submitted_floors',
                'has_basement',
                'remarks',
                'verified_data_json',
                'verification_answers_json',
                'draft_saved_at',
            ]);
        });
    }
};
