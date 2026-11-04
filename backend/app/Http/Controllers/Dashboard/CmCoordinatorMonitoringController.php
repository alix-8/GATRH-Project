<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Student;
use App\Services\Dashboard\CmCoordinatorMonitoringService;
use App\Services\ProgramHead\ProgramHeadAttendanceWorkbookService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CmCoordinatorMonitoringController extends Controller
{
    public function __construct(private CmCoordinatorMonitoringService $monitoringService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'program_id' => ['nullable', 'exists:programs,id'],
            'week' => ['nullable', 'string', 'regex:/^(all|[1-5])$/'],
            'program_code' => ['nullable', 'string', 'max:50'],
            'gathering_type' => ['nullable', 'in:all,pm,ws,tg'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->monitoringService->getPayload($filters));
    }

    public function showCourse(Request $request, string $program, int $yearLevel): JsonResponse
    {
        $filters = $request->validate([
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'week' => ['required', 'integer', 'between:1,5'],
        ]);

        $details = $this->monitoringService->getCourseDetails($program, $yearLevel, $filters);

        if ($details === null) {
            return response()->json(['message' => 'Course or reporting week not found.'], 404);
        }

        return response()->json($details);
    }

    public function exportIncompleteEntry(
        Request $request,
        string $program,
        int $yearLevel,
        ProgramHeadAttendanceWorkbookService $workbookService
    ) {
        $filters = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'week' => ['required', 'integer', 'between:1,5'],
            'gathering_type' => ['required', 'in:pm,ws,tg'],
        ]);

        $details = $this->monitoringService->getCourseDetails($program, $yearLevel, $filters);
        $entry = $details === null ? null : $details['incomplete_entries']->firstWhere('gathering_type', $filters['gathering_type']);
        if (! $entry || ! $entry['record_details']) {
            return response()->json(['message' => 'Incomplete attendance entry not found.'], 404);
        }

        $rows = $entry['record_details']['students'];
        $students = Student::query()->with('escalation')->whereIn('id', $rows->pluck('student_id'))->get()->keyBy('id');
        $reportingDay = $details['week']['gathering_ranges'][$filters['gathering_type']]['start'];
        $records = new EloquentCollection($rows->map(function (array $row) use ($students, $program, $yearLevel, $reportingDay, $filters) {
            $record = new AttendanceRecord([
                'student_id' => $row['student_id'],
                'program' => strtoupper($program),
                'year_level' => $yearLevel,
                'gathering_type' => $filters['gathering_type'],
                'day' => $row['day'] ?: $reportingDay,
                'reporting_day' => $reportingDay,
                'batch' => $row['batch'],
                'venue' => $row['venue'],
                'status' => $row['status'],
            ]);
            $record->setRelation('student', $students->get($row['student_id']));

            return $record;
        })->all());

        $path = $workbookService->create($records);
        $safeProgram = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', strtoupper($program)), '-_') ?: 'Course';
        $filename = sprintf(
            '%s_%d(%s - %s).%s',
            $safeProgram,
            $yearLevel,
            $entry['gathering'],
            $entry['record_details']['week_range']['label'],
            $workbookService->fileExtension()
        );

        return response()->download($path, $filename, [
            'Content-Type' => $workbookService->mimeType(),
            'Access-Control-Expose-Headers' => 'Content-Disposition',
        ])->deleteFileAfterSend(true);
    }
}
