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

// CAD tagging APIs
Route::get('/cad-submissions/{id}/tagging-workspace', [App\Http\Controllers\CadTaggingApiController::class, 'workspace'])->name('api.cad.workspace');
Route::post('/cad-submissions/{id}/predictions/import', [App\Http\Controllers\CadTaggingApiController::class, 'importPredictions'])->name('api.cad.predictions.import');
Route::get('/cad-submissions/{id}/tags', [App\Http\Controllers\CadTaggingApiController::class, 'listTags'])->name('api.cad.tags.list');
Route::post('/cad-submissions/{id}/tags', [App\Http\Controllers\CadTaggingApiController::class, 'createTag'])->name('api.cad.tags.create');
Route::put('/cad-submissions/{id}/tags/{tagId}', [App\Http\Controllers\CadTaggingApiController::class, 'updateTag'])->name('api.cad.tags.update');
Route::delete('/cad-submissions/{id}/tags/{tagId}', [App\Http\Controllers\CadTaggingApiController::class, 'deleteTag'])->name('api.cad.tags.delete');
Route::post('/cad-submissions/{id}/predictions/{predictionId}/review', [App\Http\Controllers\CadTaggingApiController::class, 'reviewPrediction'])->name('api.cad.predictions.review');
Route::post('/cad-submissions/{id}/predictions/bulk-review', [App\Http\Controllers\CadTaggingApiController::class, 'bulkReview'])->name('api.cad.predictions.bulk-review');
Route::post('/cad-submissions/{id}/tags/submit-verified', [App\Http\Controllers\CadTaggingApiController::class, 'submitVerified'])->name('api.cad.tags.submit-verified');
Route::post('/cad-submissions/{id}/tags/{tagId}/promote-gold', [App\Http\Controllers\CadTaggingApiController::class, 'promoteGold'])->name('api.cad.tags.promote-gold');
Route::post('/cad-submissions/{id}/evaluate', [App\Http\Controllers\CadTaggingApiController::class, 'evaluate'])->name('api.cad.evaluate');
Route::post('/map-approval/{drawing}/run-validation', [MapApprovalApiController::class, 'runValidation'])->name('api.map-approval.run-validation');
Route::get('/map-approval/{drawing}/report', [MapApprovalApiController::class, 'report'])->name('api.map-approval.report');
