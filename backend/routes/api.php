<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceDraftController;
use App\Http\Controllers\EscalationController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\DashboardController;
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
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

/*
|--------------------------------------------------------------------------
| Protected Sanctum Routes Here
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/students', [StudentController::class, 'index']);
    Route::get('/students/attendance-snapshot', [StudentController::class, 'attendanceSnapshot']);
    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [ProfileController::class, 'changePassword']);
    Route::patch('/profile/contact', [ProfileController::class, 'changeContact']);

    //attendance page
    Route::delete('/attendance/drafts', [AttendanceDraftController::class, 'destroyMany']);
    Route::get('/attendance', [AttendanceController::class, 'index']);

    // Secretary (Submission, Parsing, and Reminders)
    Route::middleware('role:secretary')->group(function () {

        // Template Generator
        Route::get('/attendance/generate-template', [AttendanceController::class, 'generateTemplate']);

        Route::post('/attendance/parse-text', [AttendanceController::class, 'parseText']);
        // Route::patch('/attendance/reparse', [AttendanceController::class, 'reparseLines']);
        Route::post('/attendance/submit', [AttendanceController::class, 'store']);
        Route::get('/attendance/session', [AttendanceController::class, 'showGatheringRecords']);

        // Attendance Drafts Routes
        Route::get('/attendance/drafts/{id}', [AttendanceDraftController::class, 'show']);
        Route::post('/attendance/drafts/{id}/submit', [AttendanceDraftController::class, 'submit']);

        // Reminder routes for custom reminders
        Route::apiResource('reminders', ReminderController::class);
        // Reminder routes for gathering reminders and submission countdowns
        Route::post('reminders/gathering', [ReminderController::class, 'storeGathering']);
        Route::get('reminders', [ReminderController::class, 'index']);
        Route::patch('reminders/{id}/complete', [ReminderController::class, 'markCompleted']);
    });
        
    // Program Head & CM Coordinator Scopes (Validation & Escalations)
    Route::middleware('role:program_head,cm_coordinator')->group(function () {
        // Route::get('/students', [StudentController::class, 'index']);
        
        Route::patch('/attendance/validate', [AttendanceController::class, 'validateRecord']);
        Route::get('/escalations', [EscalationController::class, 'index']);
        Route::get('/escalations/{id}', [EscalationController::class, 'show']);
        Route::patch('/escalations/{id}', [EscalationController::class, 'update']);
    });

    // CM Coordinator & Chancellor Scopes (Report Summaries)
    Route::middleware('role:cm_coordinator,chancellor')->group(function () {
        Route::get('/reports/summary', [AttendanceController::class, 'summaryReport']);
    });
});