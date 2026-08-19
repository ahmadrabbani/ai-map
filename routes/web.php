<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


use App\Http\Controllers\PlanCheckController;
use App\Http\Controllers\CadComplianceController;
use App\Http\Controllers\CadExpertLabelController;
use App\Http\Controllers\CadApprovalWizardController;
use App\Http\Controllers\BuildingPlanApplicationController;
use App\Http\Controllers\BuildingPlanAiReportController;
use App\Http\Controllers\BuildingPlanChatController;
use App\Http\Controllers\AdminApplicantChatController;
use App\Http\Controllers\AdEpermitReviewController;
use App\Http\Controllers\AdEpermitAuthController;
use App\Http\Controllers\DdtpReviewController;
use App\Http\Controllers\PublicApplicantAuthController;
use App\Http\Controllers\PublicBuildingPlanPortalController;
use App\Http\Controllers\PublicBuildingPlanChatController;
use App\Http\Controllers\CadTaggingDashboardController;

Route::get('/admin/plan/check-setback', [PlanCheckController::class, 'showForm'])
    ->name('admin.plan.check-setback.form');

Route::post('/admin/plan/check-setback', [PlanCheckController::class, 'checkSetbackWeb'])
    ->name('admin.plan.check-setback.submit');

Route::get('/admin/plan/cad-compliance', [CadComplianceController::class, 'index'])
    ->name('admin.plan.cad-compliance.form');

Route::post('/admin/plan/cad-compliance', [CadComplianceController::class, 'submit'])
    ->name('admin.plan.cad-compliance.submit');

Route::get('/admin/plan/cad-submissions/{id}/compliance', [CadComplianceController::class, 'show'])
    ->name('admin.plan.cad-compliance.show');

Route::post('/admin/plan/cad-submissions/{id}/rerun', [CadComplianceController::class, 'rerunWithLabels'])
    ->name('admin.plan.cad-compliance.rerun');
Route::post('/admin/plan/cad-submissions/{id}/semantic-pipeline', [CadComplianceController::class, 'runSemanticPipeline'])
    ->name('admin.plan.cad-compliance.semantic-pipeline');

// Stream overlay PDF through Laravel to avoid web-server 403s on /storage
Route::get('/admin/plan/cad-submissions/{id}/overlay', [CadComplianceController::class, 'overlay'])
    ->name('admin.plan.cad-compliance.overlay');

Route::get('/admin/plan/cad-submissions/{id}/drawing', [CadComplianceController::class, 'drawing'])
    ->name('admin.plan.cad-compliance.drawing');

Route::get('/admin/plan/cad-submissions/{id}/label', [CadExpertLabelController::class, 'edit'])
    ->name('admin.plan.cad-expert-label.edit');

Route::post('/admin/plan/cad-submissions/{id}/label', [CadExpertLabelController::class, 'store'])
    ->name('admin.plan.cad-expert-label.store');


Route::get('/admin/plan/cad-submissions/{id}/viewer', [CadExpertLabelController::class, 'viewer'])
    ->name('admin.plan.cad-layer-viewer');

Route::get('/admin/plan/cad-submissions/{id}/planner-review', [CadExpertLabelController::class, 'plannerReview'])
    ->name('admin.plan.cad-planner-review');

Route::post('/admin/plan/cad-submissions/{id}/planner-review/confirm-measurements', [CadExpertLabelController::class, 'confirmPlannerMeasurements'])
    ->name('admin.plan.cad-planner-review.confirm-measurements');
Route::post('/admin/plan/cad-submissions/{id}/planner-review/decision', [CadExpertLabelController::class, 'savePlannerDecision'])
    ->name('admin.plan.cad-planner-review.decision');

Route::get('/admin/plan/cad-submissions/{id}/dxf', [CadExpertLabelController::class, 'dxf'])
    ->name('admin.plan.cad-submissions.dxf');

Route::post('/admin/plan/cad-submissions/{id}/layer-map', [CadExpertLabelController::class, 'storeLayerMap'])
    ->name('admin.plan.cad-layer-map.store');
Route::post('/admin/plan/cad-submissions/{id}/expert-results', [CadExpertLabelController::class, 'storeExpertResult'])
    ->name('admin.plan.cad-expert-results.store');

Route::get('/admin/plan/cad-submissions/{id}/entities', [CadExpertLabelController::class, 'entities'])
    ->name('admin.plan.cad-entities.index');
Route::get('/admin/plan/cad-submissions/{id}/labels', [CadExpertLabelController::class, 'labels'])
    ->name('admin.plan.cad-labels.index');
Route::post('/admin/plan/cad-submissions/{id}/label-mappings', [CadExpertLabelController::class, 'createLabelMappings'])
    ->name('admin.plan.cad-label-mappings.store');
Route::delete('/admin/plan/cad-submissions/{id}/label-mappings/{mappingId}', [CadExpertLabelController::class, 'deleteLabelMapping'])
    ->name('admin.plan.cad-label-mappings.destroy');
Route::post('/admin/plan/cad-submissions/{id}/auto-suggest-mappings', [CadExpertLabelController::class, 'autoSuggestMappings'])
    ->name('admin.plan.cad-label-mappings.auto-suggest');
Route::get('/admin/plan/cad-submissions/{id}/mapping-report', [CadExpertLabelController::class, 'mappingReport'])
    ->name('admin.plan.cad-label-mappings.report');
Route::get('/admin/plan/cad-submissions/{id}/expert-markings', [CadExpertLabelController::class, 'expertMarkings'])
    ->name('admin.plan.cad-expert-markings.index');
Route::post('/admin/plan/cad-submissions/{id}/expert-markings', [CadExpertLabelController::class, 'storeExpertMarking'])
    ->name('admin.plan.cad-expert-markings.store');
Route::put('/admin/plan/cad-submissions/{id}/expert-markings/{markingId}', [CadExpertLabelController::class, 'updateExpertMarking'])
    ->name('admin.plan.cad-expert-markings.update');
Route::delete('/admin/plan/cad-submissions/{id}/expert-markings/{markingId}', [CadExpertLabelController::class, 'deleteExpertMarking'])
    ->name('admin.plan.cad-expert-markings.destroy');
Route::post('/admin/plan/cad-submissions/{id}/expert-markings/{markingId}/confirm', [CadExpertLabelController::class, 'confirmExpertMarking'])
    ->name('admin.plan.cad-expert-markings.confirm');
Route::get('/admin/plan/cad-submissions/{id}/expert-marking-report', [CadExpertLabelController::class, 'expertMarkingReport'])
    ->name('admin.plan.cad-expert-markings.report');
Route::post('/admin/plan/cad-submissions/{id}/text-references', [CadExpertLabelController::class, 'storeCadTextReferences'])
    ->name('admin.plan.cad-text-references.store');
Route::post('/admin/plan/cad-submissions/{id}/assistant-chat', [CadExpertLabelController::class, 'assistantChat'])
    ->name('admin.plan.cad-assistant-chat');

Route::get('/admin/plan/cad-tagging/building-plans', [CadTaggingDashboardController::class, 'buildingPlans'])
    ->name('admin.plan.cad-tagging.building-plans');
Route::get('/admin/plan/cad-tagging/accuracy', [CadTaggingDashboardController::class, 'accuracy'])
    ->name('admin.plan.cad-tagging.accuracy');

Route::get('/admin/plan/approval-wizard', [CadApprovalWizardController::class, 'index'])
    ->name('admin.plan.approval-wizard.index');

Route::get('/admin/plan/approval-wizard/create', [CadApprovalWizardController::class, 'create'])
    ->name('admin.plan.approval-wizard.create');

Route::post('/admin/plan/approval-wizard/details', [CadApprovalWizardController::class, 'storeDetails'])
    ->name('admin.plan.approval-wizard.store-details');

Route::get('/admin/plan/approval-wizard/{application}', [CadApprovalWizardController::class, 'show'])
    ->name('admin.plan.approval-wizard.show');

Route::get('/admin/plan/approval-wizard/{application}/verification', [CadApprovalWizardController::class, 'verification'])
    ->name('admin.plan.approval-wizard.verification');

Route::post('/admin/plan/approval-wizard/{application}/verification', [CadApprovalWizardController::class, 'saveVerification'])
    ->name('admin.plan.approval-wizard.save-verification');

Route::post('/admin/plan/approval-wizard/{application}/details', [CadApprovalWizardController::class, 'updateDetails'])
    ->name('admin.plan.approval-wizard.update-details');

Route::post('/admin/plan/approval-wizard/{application}/draft', [CadApprovalWizardController::class, 'saveDraft'])
    ->name('admin.plan.approval-wizard.save-draft');

Route::post('/admin/plan/approval-wizard/{application}/plans', [CadApprovalWizardController::class, 'uploadPlans'])
    ->name('admin.plan.approval-wizard.upload-plans');

Route::post('/admin/plan/approval-wizard/{application}/plans/{plan}/process', [CadApprovalWizardController::class, 'processPlan'])
    ->name('admin.plan.approval-wizard.process-plan');

Route::post('/admin/plan/approval-wizard/{application}/plans/{plan}/rerun', [CadApprovalWizardController::class, 'rerunPlan'])
    ->name('admin.plan.approval-wizard.rerun-plan');

Route::get('/admin/plan/approval-wizard/{application}/summary', [CadApprovalWizardController::class, 'summary'])
    ->name('admin.plan.approval-wizard.summary');

Route::get('/admin/plan/approval-wizard/{application}/expert-review', [CadApprovalWizardController::class, 'expertReview'])
    ->name('admin.plan.approval-wizard.expert-review');

Route::post('/admin/plan/approval-wizard/{application}/expert-markings', [CadApprovalWizardController::class, 'saveExpertMarking'])
    ->name('admin.plan.approval-wizard.save-expert-marking');

Route::get('/admin/plan/approval-wizard/{application}/report', [CadApprovalWizardController::class, 'report'])
    ->name('admin.plan.approval-wizard.report');

Route::post('/admin/plan/approval-wizard/{application}/report', [CadApprovalWizardController::class, 'generateReport'])
    ->name('admin.plan.approval-wizard.generate-report');

Route::post('/admin/plan/approval-wizard/{application}/submit', [CadApprovalWizardController::class, 'submitForProcessing'])
    ->name('admin.plan.approval-wizard.submit');

Route::post('/admin/plan/approval-wizard/faq', [CadApprovalWizardController::class, 'faq'])
    ->name('admin.plan.approval-wizard.faq');

/*
|--------------------------------------------------------------------------
| Building Plan AI Approval Workflow
|--------------------------------------------------------------------------
*/
Route::get('/admin/plan/building-plan-applications', [BuildingPlanApplicationController::class, 'index'])
    ->name('admin.plan.bp.index');
Route::post('/admin/plan/building-plan-applications', [BuildingPlanApplicationController::class, 'store'])
    ->name('admin.plan.bp.store');
Route::get('/admin/plan/building-plan-applications/{application}', [BuildingPlanApplicationController::class, 'show'])
    ->name('admin.plan.bp.show');
Route::get('/admin/plan/building-plan-applications/{application}/portal', [BuildingPlanApplicationController::class, 'portal'])
    ->name('admin.plan.bp.portal');
Route::post('/admin/plan/building-plan-applications/{application}/submit-ad', [BuildingPlanApplicationController::class, 'submitToAdEpermit'])
    ->name('admin.plan.bp.submit-ad');

Route::get('/admin/plan/building-plan-applications/{application}/report', [BuildingPlanAiReportController::class, 'show'])
    ->name('admin.plan.bp.report.show');
Route::get('/admin/plan/building-plan-applications/{application}/report/download', [BuildingPlanAiReportController::class, 'download'])
    ->name('admin.plan.bp.report.download');
Route::get('/admin/plan/building-plan-verification/{token}', [BuildingPlanAiReportController::class, 'verify'])
    ->name('admin.plan.bp.verify');

Route::post('/admin/plan/building-plan-applications/{application}/chat', [BuildingPlanChatController::class, 'store'])
    ->name('admin.plan.bp.chat.store');
Route::get('/admin/plan/building-plan-applications/{application}/chat', [BuildingPlanChatController::class, 'index'])
    ->name('admin.plan.bp.chat.index');

Route::get('/admin/plan/ad-epermit/login', [AdEpermitAuthController::class, 'showLogin'])
    ->name('admin.plan.bp.ad.login');
Route::post('/admin/plan/ad-epermit/login', [AdEpermitAuthController::class, 'login'])
    ->name('admin.plan.bp.ad.login.store');
Route::post('/admin/plan/ad-epermit/logout', [AdEpermitAuthController::class, 'logout'])
    ->name('admin.plan.bp.ad.logout');

Route::middleware('ad.epermit')->group(function () {
    Route::get('/admin/plan/ad-epermit', [AdEpermitReviewController::class, 'index'])
        ->name('admin.plan.bp.ad.index');
    Route::get('/admin/plan/ad-epermit/{application}', [AdEpermitReviewController::class, 'show'])
        ->name('admin.plan.bp.ad.show');
    Route::post('/admin/plan/ad-epermit/{application}', [AdEpermitReviewController::class, 'update'])
        ->name('admin.plan.bp.ad.update');
    Route::post('/admin/plan/ad-epermit/{application}/generate-cad-analysis', [AdEpermitReviewController::class, 'generateCadAnalysis'])
        ->name('admin.plan.bp.ad.generate-cad-analysis');
    Route::post('/admin/plan/ad-epermit/{application}/imagery-label', [AdEpermitReviewController::class, 'saveImageryLabel'])
        ->name('admin.plan.bp.ad.imagery-label');
    Route::post('/admin/plan/ad-epermit/{application}/site-review', [AdEpermitReviewController::class, 'saveSiteReview'])
        ->name('admin.plan.bp.ad.site-review');
    Route::post('/admin/plan/ad-epermit/{application}/push-dfps', [AdEpermitReviewController::class, 'pushToDfps'])
        ->name('admin.plan.bp.ad.push-dfps');
    Route::get('/admin/plan/ad-epermit/{application}/applicant-chat', [AdminApplicantChatController::class, 'index'])
        ->name('admin.plan.bp.ad.applicant-chat.index');
    Route::post('/admin/plan/ad-epermit/{application}/applicant-chat', [AdminApplicantChatController::class, 'store'])
        ->name('admin.plan.bp.ad.applicant-chat.store');
});

Route::get('/admin/plan/ddtp', [DdtpReviewController::class, 'index'])
    ->name('admin.plan.bp.ddtp.index');
Route::get('/admin/plan/ddtp/{application}', [DdtpReviewController::class, 'show'])
    ->name('admin.plan.bp.ddtp.show');
Route::post('/admin/plan/ddtp/{application}', [DdtpReviewController::class, 'update'])
    ->name('admin.plan.bp.ddtp.update');

/*
|--------------------------------------------------------------------------
| Public Applicant Portal - Building Plan AI
|--------------------------------------------------------------------------
*/
Route::middleware('bp.applicant.guest')->group(function () {
    Route::get('/building-plan-ai/login', [PublicApplicantAuthController::class, 'showLogin'])
        ->name('public.bp.login');
    Route::post('/building-plan-ai/login', [PublicApplicantAuthController::class, 'login'])
        ->name('public.bp.login.store');
    Route::get('/building-plan-ai/register', [PublicApplicantAuthController::class, 'showRegister'])
        ->name('public.bp.register');
    Route::post('/building-plan-ai/register', [PublicApplicantAuthController::class, 'register'])
        ->name('public.bp.register.store');
});
Route::post('/building-plan-ai/logout', [PublicApplicantAuthController::class, 'logout'])
    ->name('public.bp.logout');

Route::middleware('bp.applicant')->group(function () {
    Route::get('/building-plan-ai/dashboard', [PublicBuildingPlanPortalController::class, 'dashboard'])
        ->name('public.bp.dashboard');
    Route::get('/building-plan-ai/applications/create', [PublicBuildingPlanPortalController::class, 'create'])
        ->name('public.bp.applications.create');
    Route::post('/building-plan-ai/applications/precheck', [PublicBuildingPlanPortalController::class, 'precheck'])
        ->name('public.bp.applications.precheck');
    Route::post('/building-plan-ai/applications/store', [PublicBuildingPlanPortalController::class, 'store'])
        ->name('public.bp.applications.store');
    Route::get('/building-plan-ai/applications/{id}', [PublicBuildingPlanPortalController::class, 'show'])
        ->name('public.bp.applications.show');
    Route::get('/building-plan-ai/applications/{id}/edit', [PublicBuildingPlanPortalController::class, 'edit'])
        ->name('public.bp.applications.edit');
    Route::get('/building-plan-ai/applications/{id}/report', [PublicBuildingPlanPortalController::class, 'report'])
        ->name('public.bp.applications.report');
    Route::get('/building-plan-ai/applications/{id}/download-report', [PublicBuildingPlanPortalController::class, 'downloadReport'])
        ->name('public.bp.applications.download-report');
    Route::get('/building-plan-ai/applications/{id}/documents/{documentId}', [PublicBuildingPlanPortalController::class, 'document'])
        ->name('public.bp.applications.document');
    Route::get('/building-plan-ai/applications/{id}/plan-pdf', [PublicBuildingPlanPortalController::class, 'planPdf'])
        ->name('public.bp.applications.plan-pdf');
    Route::get('/building-plan-ai/applications/{id}/chat', [PublicBuildingPlanChatController::class, 'index'])
        ->name('public.bp.applications.chat.index');
    Route::post('/building-plan-ai/applications/{id}/chat', [PublicBuildingPlanChatController::class, 'store'])
        ->name('public.bp.applications.chat.store');
});
