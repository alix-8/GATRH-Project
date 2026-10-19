<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminActivityLogController;
use App\Http\Controllers\Admin\AdminProgramController;
use App\Http\Controllers\Admin\AdminSystemSettingController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminStudentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\Attendance\AttendanceDraftController;
use App\Http\Controllers\Dashboard\CmCoordinatorMonitoringController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Escalations\EscalationController;
use App\Http\Controllers\ProgramHead\ProgramHeadSubmissionController;
use App\Http\Controllers\Reminders\ReminderController;
use App\Http\Controllers\Students\StudentController;
use App\Http\Middleware\ApplySystemTimezone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::get('/health', function () {
    return response()->json([
        'message' => 'API is working',
        'status' => 'success',
        'app' => config('app.name'),
        'timestamp' => now()->setTimezone('Asia/Manila')->toIso8601String(),
    ]);
});

Route::post('/internal/scheduler/run', function (Request $request) {
    $secret = config('services.scheduler.secret');
    $providedSecret = $request->bearerToken();

    abort_unless(
        is_string($secret)
            && $secret !== ''
            && is_string($providedSecret)
            && hash_equals($secret, $providedSecret),
        403
    );

    $exitCode = Artisan::call('schedule:run');

    if ($exitCode !== 0) {
        return response()->json(['message' => 'Scheduler run failed.'], 500);
    }

    return response()->json([
        'message' => 'Scheduler checked successfully.',
        'checked_at' => now()->toIso8601String(),
    ]);
})->middleware('throttle:10,1');

Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/verify-reset-code', [AuthController::class, 'verifyPasswordResetCode']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', ApplySystemTimezone::class])->group(function () {
    /* Account and profile */
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [ProfileController::class, 'changePassword']);
    Route::patch('/profile/contact', [ProfileController::class, 'changeContact']);

    /* Shared dashboard and student data */
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('role:secretary,program_head,program_secretary,cm_coordinator,chancellor');
    Route::get('/students', [StudentController::class, 'index']);
    Route::get('/students/attendance-snapshot', [StudentController::class, 'attendanceSnapshot']);
    Route::get('/students/{id}/overview', [StudentController::class, 'overview']);

    /* Reminders */
    Route::get('/reminders/role-summary', [ReminderController::class, 'roleSummary'])
        ->middleware('role:program_head,cm_coordinator,program_secretary,chancellor');
    Route::apiResource('reminders', ReminderController::class);

    /* Shared attendance data */
    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::delete('/attendance/drafts', [AttendanceDraftController::class, 'destroyMany']);

    /* Administrator */
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/dashboard', [AdminDashboardController::class, 'index']);
        Route::get('/admin/activity-log', [AdminActivityLogController::class, 'index']);
        Route::get('/admin/settings/general', [AdminSystemSettingController::class, 'show']);
        Route::patch('/admin/settings/general', [AdminSystemSettingController::class, 'update']);
        Route::get('/admin/settings/gatherings', [AdminSystemSettingController::class, 'showGatheringSchedules']);
        Route::patch('/admin/settings/gatherings', [AdminSystemSettingController::class, 'updateGatheringSchedules']);
        Route::get('/admin/programs', [AdminProgramController::class, 'index']);
        Route::get('/admin/program-head-options', [AdminProgramController::class, 'headOptions']);
        Route::post('/admin/programs', [AdminProgramController::class, 'store']);
        Route::get('/admin/programs/{program}', [AdminProgramController::class, 'show']);
        Route::patch('/admin/programs/{program}', [AdminProgramController::class, 'update']);
        Route::get('/admin/users', [AdminUserController::class, 'index']);
        Route::post('/admin/users', [AdminUserController::class, 'store']);
        Route::patch('/admin/users/{user}', [AdminUserController::class, 'update']);
        Route::get('/admin/students', [AdminStudentController::class, 'index']);
        Route::post('/admin/students', [AdminStudentController::class, 'store']);
        Route::get('/admin/students/{student}', [AdminStudentController::class, 'show']);
        Route::patch('/admin/students/{student}', [AdminStudentController::class, 'update']);
        Route::delete('/admin/students/{student}', [AdminStudentController::class, 'destroy']);
        Route::post('/admin/students/school-year-rollover', [AdminStudentController::class, 'rolloverSchoolYear']);
    });

    /* Secretary */
    Route::middleware('role:secretary')->group(function () {
        Route::get('/attendance/generate-template', [AttendanceController::class, 'generateTemplate']);
        Route::post('/attendance/check-duplicates', [AttendanceController::class, 'checkDuplicates']);
        Route::post('/attendance/parse-text', [AttendanceController::class, 'parseText']);
        Route::post('/attendance/parse-batch-text', [AttendanceController::class, 'parseBatchText']);
        Route::post('/attendance/submit', [AttendanceController::class, 'store']);
        Route::get('/attendance/session', [AttendanceController::class, 'showGatheringRecords']);

        Route::get('/attendance/drafts/{id}', [AttendanceDraftController::class, 'show']);
        Route::patch('/attendance/drafts/{id}', [AttendanceDraftController::class, 'update']);
        Route::post('/attendance/drafts/{id}/submit', [AttendanceDraftController::class, 'submit']);

        Route::post('/reminders/gathering', [ReminderController::class, 'storeGathering']);
        Route::patch('/reminders/{id}/complete', [ReminderController::class, 'markCompleted']);
    });

    /* Program head and program secretary */
    Route::middleware('role:program_head,program_secretary')->group(function () {
        Route::get('/program-head/submissions', [ProgramHeadSubmissionController::class, 'index']);
        Route::get('/program-head/records', [ProgramHeadSubmissionController::class, 'records']);
        Route::get('/program-head/records/export', [ProgramHeadSubmissionController::class, 'exportRecords']);

        Route::patch('/attendance/validate', [AttendanceController::class, 'validateRecord']);
        Route::get('/attendance/review/{batchKey}', [AttendanceController::class, 'reviewSubmission'])
            ->where('batchKey', '.*');
        Route::patch('/attendance/review/{batchKey}/approve', [AttendanceController::class, 'approveSubmission'])
            ->where('batchKey', '.*');

        Route::middleware('role:program_head')->group(function () {
            Route::get('/escalations', [EscalationController::class, 'index']);
            Route::get('/escalations/{id}', [EscalationController::class, 'show']);
            // Route::patch('/escalations/{id}', [EscalationController::class, 'update']);
        });
    });

    /* CM coordinator */
    Route::middleware('role:cm_coordinator')->group(function () {
        Route::get('/monitoring', [CmCoordinatorMonitoringController::class, 'index']);
        Route::get('/monitoring/courses/{program}/{yearLevel}', [CmCoordinatorMonitoringController::class, 'showCourse'])
            ->where(['program' => '[A-Za-z0-9_-]+', 'yearLevel' => '[0-9]+']);
    });

    /* CM coordinator and chancellor reports */
    Route::middleware('role:cm_coordinator,chancellor')->group(function () {
        Route::get('/reports/summary', [AttendanceController::class, 'summaryReport']);
    });
});
