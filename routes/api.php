<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PlanCheckController;

Route::post('/plan/check-setback', [PlanCheckController::class, 'checkSetback']);
