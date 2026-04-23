<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


use App\Http\Controllers\PlanCheckController;
use App\Http\Controllers\CadComplianceController;
use App\Http\Controllers\CadExpertLabelController;

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

Route::get('/admin/plan/cad-submissions/{id}/dxf', [CadExpertLabelController::class, 'dxf'])
    ->name('admin.plan.cad-submissions.dxf');

Route::post('/admin/plan/cad-submissions/{id}/layer-map', [CadExpertLabelController::class, 'storeLayerMap'])
    ->name('admin.plan.cad-layer-map.store');
Route::post('/admin/plan/cad-submissions/{id}/expert-results', [CadExpertLabelController::class, 'storeExpertResult'])
    ->name('admin.plan.cad-expert-results.store');
