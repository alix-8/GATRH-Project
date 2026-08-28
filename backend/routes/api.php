<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\EscalationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes Here
|--------------------------------------------------------------------------
*/

// test route to check if the API is working
Route::get('/health', function () {
    return response()->json([
        'message' => 'API is working',
        'status' => 'success',
        'app'=> config('app.name'),
        'timestamp' => now()->setTimezone('Asia/Manila')->toIso8601String(),
        ]);
});

Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Protected Sanctum Routes Here
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Secretary & Program Head Scopes (Submissions & Parsing)
    Route::middleware('role:secretary,program_head')->group(function () {

        // Template Generator
        Route::get('/attendance/generate-template', [AttendanceController::class, 'generateTemplate']);

        Route::post('/attendance/parse-text', [AttendanceController::class, 'parseText']);
        Route::patch('/attendance/reparse', [AttendanceController::class, 'reparseLines']);
        Route::post('/attendance/submit', [AttendanceController::class, 'store']);
    });
        
    // Program Head & CM Coordinator Scopes (Validation & Escalations)
    Route::middleware('role:program_head,cm_coordinator')->group(function () {
        Route::get('/students', [StudentController::class, 'index']);
        
        Route::patch('/attendance/{id}/validate', [AttendanceController::class, 'validateRecord']);
        Route::get('/escalations', [EscalationController::class, 'index']);
        Route::get('/escalations/{id}', [EscalationController::class, 'show']);
        Route::patch('/escalations/{id}', [EscalationController::class, 'update']);
    });

    // CM Coordinator & Chancellor Scopes (Report Summaries)
    Route::middleware('role:cm_coordinator,chancellor')->group(function () {
        Route::get('/reports/summary', [AttendanceController::class, 'summaryReport']);
    });
});