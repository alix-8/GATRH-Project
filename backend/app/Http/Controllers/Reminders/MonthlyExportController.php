<?php

namespace App\Http\Controllers\Reminders;

use App\Http\Controllers\Controller;
use App\Models\MonthlyExportTask;
use App\Services\ProgramHead\ProgramHeadAttendanceWorkbookService;
use App\Services\Reminders\MonthlyExportService;
use App\Support\SystemTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MonthlyExportController extends Controller
{
    public function preview(Request $request, int $reminder, MonthlyExportService $exports)
    {
        $task = MonthlyExportTask::where('reminder_id', $reminder)->firstOrFail();
        $exports->authorize($request->user(), $task);
        $snapshot = $exports->snapshot($task);
        return response()->json([
            'program' => $task->program, 'year_level' => (int) $task->year_level,
            'month' => $task->month->format('F Y'),
            'coverage' => 'All reporting weeks · PM, WS, TG',
            'students_included' => $snapshot['students']->count(),
            'pending_entries' => $snapshot['pending'], 'missing_entries' => $snapshot['missing'],
        ]);
    }

    public function download(Request $request, int $reminder, MonthlyExportService $exports, ProgramHeadAttendanceWorkbookService $workbooks)
    {
        $task = MonthlyExportTask::where('reminder_id', $reminder)->firstOrFail();
        $exports->authorize($request->user(), $task);
        $snapshot = $exports->refresh($task);
        $path = $workbooks->createPeriod(
            $snapshot['students'], CarbonImmutable::parse($task->month), 'month',
            $task->program, (int) $task->year_level, null, null,
            'Exported '.$task->month->format('F Y').' · Coverage: All reporting weeks · PM, WS, TG. '
                .'Students included: '.$snapshot['students']->count().'. '
                .'Missing record means no attendance was recorded. No Response is a recorded attendance result.'
        );
        $token = Str::random(64);
        $task->update([
            'download_token' => hash('sha256', $token), 'download_hash' => $snapshot['hash'],
            'download_approved' => $snapshot['approved'], 'download_approval_set' => $snapshot['approvalSet'],
            'download_expires_at' => SystemTimezone::now()->addHour(),
        ]);
        $program = preg_replace('/[^A-Za-z0-9_-]/', '-', $task->program);
        return response()->download($path, "$program-{$task->year_level}-{$task->month->format('Y-m')}-monthly.xlsx", [
            'Content-Type' => $workbooks->mimeType(),
            'X-Monthly-Export-Token' => $token,
            'Access-Control-Expose-Headers' => 'Content-Disposition, X-Monthly-Export-Token',
        ])->deleteFileAfterSend(true);
    }

    public function complete(Request $request, int $reminder, MonthlyExportService $exports)
    {
        $validated = $request->validate(['token' => 'required|string|size:64']);
        $task = MonthlyExportTask::where('reminder_id', $reminder)->firstOrFail();
        $current = $exports->acknowledge($request->user(), $task, $validated['token']);
        return response()->json(['current' => $current, 'message' => $current
            ? 'Monthly export downloaded. Your reminder is Done.'
            : 'Downloaded. Records changed during export—export again to complete this reminder.']);
    }
}
