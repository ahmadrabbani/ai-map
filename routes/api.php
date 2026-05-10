<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PlanCheckController;
use App\Http\Controllers\MapApprovalApiController;

Route::post('/plan/check-setback', [PlanCheckController::class, 'checkSetback']);

Route::post('/map-approval/upload', [MapApprovalApiController::class, 'upload'])->name('api.map-approval.upload');
Route::get('/map-approval/{drawing}/entities', [MapApprovalApiController::class, 'entities'])->name('api.map-approval.entities');
Route::get('/map-approval/{drawing}/mapping-summary', [MapApprovalApiController::class, 'mappingSummary'])->name('api.map-approval.mapping-summary');
Route::get('/map-approval/{drawing}/layer-suggestions', [MapApprovalApiController::class, 'layerSuggestions'])->name('api.map-approval.layer-suggestions');
Route::post('/map-approval/{drawing}/manual-map', [MapApprovalApiController::class, 'manualMap'])->name('api.map-approval.manual-map');
Route::post('/map-approval/{drawing}/run-validation', [MapApprovalApiController::class, 'runValidation'])->name('api.map-approval.run-validation');
Route::get('/map-approval/{drawing}/report', [MapApprovalApiController::class, 'report'])->name('api.map-approval.report');
