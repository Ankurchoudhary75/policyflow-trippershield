<?php

use App\Http\Controllers\Api\CallbackController;
use App\Http\Controllers\Api\IssuanceController;
use App\Http\Controllers\Api\MockInsurerController;
use App\Http\Controllers\Api\PolicyRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PolicyFlow REST API Routes
|--------------------------------------------------------------------------
*/

// Policy Request Intake, Query & Issuance Submission
Route::prefix('policy-requests')->group(function () {
    Route::post('/', [PolicyRequestController::class, 'store']);
    Route::get('/', [PolicyRequestController::class, 'index']);
    Route::get('/{id}', [PolicyRequestController::class, 'show']);
    Route::post('/{id}/submit', [IssuanceController::class, 'submit']);
});

// Mock Insurer Simulation & Reconciliation Endpoints
Route::prefix('mock-insurer')->group(function () {
    Route::post('/policies', [MockInsurerController::class, 'submit']);
    Route::get('/policies/{idempotency_key}', [MockInsurerController::class, 'lookup']);
    Route::post('/callback', [CallbackController::class, 'handle']);
});
