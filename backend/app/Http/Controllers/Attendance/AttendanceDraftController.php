<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;

use App\Models\AttendanceDraft;
use App\Models\AttendanceRecord;
use App\Models\Reminder;
use App\Models\Student;
use App\Services\Audit\AuditLogService;
use App\Services\Attendance\AttendanceParsingService;
use App\Support\GatheringCycle;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AttendanceDraftController extends Controller
{
    /**
     * Resume an existing draft by ID.
     * GET /api/attendance/drafts/{id}
     */
    public function show(Request $request, int $id, AttendanceParsingService $parsingService): JsonResponse
    {
        $draft = AttendanceDraft::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'draft')
            ->first();

        if (!$draft) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Draft not found or already submitted.',
            ], 404);
        }

        $submissionDay = optional($draft->day)->toDateString();

        $students = collect($draft->parsed_data)->map(
            function ($record) use ($submissionDay) {
                // No-response records retain a required database date, but this is
                // only the gathering's scheduled day, not a date entered by the secretary.
                $dayProvided = ($record['day_provided'] ?? null) !== null
                    ? (bool) $record['day_provided']
                    : (!empty($record['day'])
                        && $record['day'] !== '---'
                        && ($record['status'] ?? null) !== 'no_response');

                return [
                    'student_id'   => $record['student_id'] ?? null,
                    'student_name' => $record['student_name'] ?? 'Unknown',
                    'status'       => $record['status'] ?? 'no_response',
                    'batch'        => !empty($record['batch'])
                        ? \Carbon\Carbon::parse($record['batch'])->format('g:ia')
                        : '---',
                    'venue'        => $record['venue'] ?? '---',

                    // For absent/no-response students, use the gathering submission date.
                    'day'          => $dayProvided ? ($record['day'] ?? $submissionDay ?? '---') : '---',
                    'day_provided' => $dayProvided,
                ];
            }
        );

        $responded = $students->where('status', 'present')->count();
        $total = $students->count();

        $type = (string) $draft->gathering_type;
        $range = GatheringCycle::getRangesForWeekStart(\Carbon\Carbon::parse($draft->day))[$type];
        $existingQuery = AttendanceRecord::where('gathering_type', $type)
            ->inReportingRange([$range['start']->toDateString(), $range['end']->toDateString()])
            ->whereHas('student', function ($query) use ($request) {
                $query->where('program', $request->user()->program)
                    ->where('year_level', $request->user()->year_level);
            });

        $existingAttendanceCount = $existingQuery->count();
        $existingDraftCount = AttendanceDraft::where('gathering_type', $type)
            ->where('status', 'draft')
            ->where('id', '!=', $draft->id)
            ->inReportingRange([$range['start']->toDateString(), $range['end']->toDateString()])
            ->whereHas('user', function ($query) use ($request) {
                $query->where('program', $request->user()->program)
                    ->where('year_level', $request->user()->year_level);
            })
            ->count();
        $duplicateCount = $existingAttendanceCount + $existingDraftCount;
        $weekLabel = $range['start']->format('F j') . ' - ' . $range['end']->format('F j, Y');

        return response()->json([
            'status' => 'success',
            'data'   => [
                'id'                 => $draft->id,
                'gathering_type'     => $draft->gathering_type,
                'day'                => optional($draft->day)->format('F j, Y'),
                'day_iso'            => optional($draft->day)->toDateString(),
                'week_range'         => [
                    'start' => $range['start']->toDateString(),
                    'end'   => $range['end']->toDateString(),
                    'label' => GatheringCycle::formatRangeLabel($range['start'], $range['end']),
                ],
                'submitted_by'       => [
                    'name' => $request->user()->name,
                    'role' => trim(strtoupper($request->user()->program) . ' ' . $this->ordinalYear($request->user()->year_level) . ' · Spiritual Secretary'),
                ],
                'total_students'     => $total,
                'responded_count'    => $responded,
                'no_response_count'  => $total - $responded,
                'students'           => $students,
                'duplicate'          => $duplicateCount > 0 ? [
                    'status'                => 'duplicate_detected',
                    'requires_confirmation' => true,
                    'gathering_type'        => $type,
                    'duplicates_count'      => $duplicateCount,
                    'week_range'            => [
                        'start' => $range['start']->toDateString(),
                        'end'   => $range['end']->toDateString(),
                        'label' => $weekLabel,
                    ],
                ] : null,
            ],
        ], 200);
    }

    /**
     * Save edits to an attendance draft without submitting it.
     * PATCH /api/attendance/drafts/{id}
     */
    public function update(
        Request $request,
        int $id,
        AttendanceParsingService $parsingService,
        AuditLogService $auditLogService
    ): JsonResponse {
        $draft = AttendanceDraft::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'draft')
            ->first();

        if (!$draft) {
            return response()->json([
                'status' => 'error',
                'message' => 'Draft not found or already submitted.',
            ], 404);
        }

        $validated = $request->validate([
            'records' => 'required|array',
            'records.*.student_id' => 'required|integer|exists:students,id',
            'records.*.day' => 'nullable|date_format:Y-m-d',
            'records.*.day_provided' => 'required|boolean',
            'records.*.batch' => 'nullable|string|max:10',
            'records.*.venue' => 'nullable|string|max:25',
            'remove_inactive_student_ids' => 'sometimes|array',
            'remove_inactive_student_ids.*' => 'integer|distinct',
        ]);

        $originalRecords = collect($draft->parsed_data ?? []);
        $originalIds = $originalRecords->pluck('student_id')->filter()->map(fn ($studentId) => (int) $studentId)->unique()->sort()->values();
        $requestedRemovalIds = collect($validated['remove_inactive_student_ids'] ?? [])->map(fn ($studentId) => (int) $studentId)->unique()->values();
        $originalInactiveIds = Student::whereKey($originalIds->all())->where('is_active', false)->pluck('id')->map(fn ($studentId) => (int) $studentId);
        if ($requestedRemovalIds->diff($originalInactiveIds)->isNotEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only inactive students already in this attendance draft can be removed.',
            ], 422);
        }
        $expectedIds = $originalIds->diff($requestedRemovalIds)->sort()->values();
        $submittedIds = collect($validated['records'])->pluck('student_id')->map(fn ($studentId) => (int) $studentId)->unique()->sort()->values();

        if ($expectedIds->all() !== $submittedIds->all() || $submittedIds->count() !== count($validated['records'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'The attendance draft must contain each original student exactly once.',
            ], 422);
        }

        $inactiveStudents = Student::whereKey($submittedIds->all())
            ->where('is_active', false)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Student $student) => ['student_id' => $student->id, 'student_name' => $student->name])
            ->values();

        if ($inactiveStudents->isNotEmpty()) {
            $message = $inactiveStudents->count() === 1
                ? "{$inactiveStudents->first()['student_name']} is already inactive and cannot be edited in this attendance draft."
                : 'These students are already inactive and cannot be edited in this attendance draft: '
                    . $inactiveStudents->pluck('student_name')->implode(', ')
                    . '.';

            return response()->json([
                'status' => 'error',
                'message' => $message,
                'inactive_students' => $inactiveStudents,
            ], 403);
        }

        $accessibleStudentCount = Student::whereKey($submittedIds->all())
            ->active()
            ->accessibleTo($request->user())
            ->count();
        if ($accessibleStudentCount !== $submittedIds->count()) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to edit attendance for one or more students.',
            ], 403);
        }

        $range = GatheringCycle::getRangesForWeekStart(\Carbon\Carbon::parse($draft->day))[(string) $draft->gathering_type];
        $formatErrors = [];
        $incompleteRecords = [];
        $parsedRecords = [];

        foreach ($validated['records'] as $record) {
            $original = $originalRecords->firstWhere('student_id', $record['student_id']) ?? [];
            $batch = trim((string) ($record['batch'] ?? ''));
            $venue = trim((string) ($record['venue'] ?? ''));
            $dayProvided = (bool) $record['day_provided'];
            $day = $dayProvided ? ($record['day'] ?? null) : null;
            $formattedBatch = $batch !== ''
                ? $parsingService->formatBatchTime($batch)
                : null;

            if ($batch !== '' && (
                !preg_match('/^(?:(?:[1-9]|1[0-2])\s*(?:AM|PM)|\d{1,2}:\d{2}(?::\d{2})?\s*(?:AM|PM)?)$/i', $batch)
                || $formattedBatch === null
            )) {
                $formatErrors[] = [
                    'student_name' => $original['student_name'] ?? 'Unknown student',
                    'raw_line' => $original['student_name'] ?? 'Unknown student',
                    'errors' => [[
                        'field' => 'batch',
                        'value' => $batch,
                        'reason' => 'Batch time must be in a valid time format, e.g. "8AM" or "8:30 AM".',
                    ]],
                ];
            }

            $dayResolution = $dayProvided
                ? $parsingService->resolveWeekdayToDate($day, $range['start'], $range['end'], $venue)
                : null;
            if ($dayProvided && !$dayResolution['success']) {
                $formatErrors[] = [
                    'student_name' => $original['student_name'] ?? 'Unknown student',
                    'raw_line' => $original['student_name'] ?? 'Unknown student',
                    'errors' => [[
                        'field' => 'day',
                        'value' => $day,
                        'reason' => $dayResolution['error'],
                    ]],
                ];
            }

            $hasBatch = $batch !== '';
            $hasVenue = $venue !== '';
            $hasAnyAttendanceValue = $dayProvided || $hasBatch || $hasVenue;
            $missing = array_values(array_filter([
                !$dayProvided ? 'day' : null,
                !$hasBatch ? 'batch' : null,
                !$hasVenue ? 'venue' : null,
            ]));
            if ($hasAnyAttendanceValue && $missing !== []) {
                $incompleteRecords[] = [
                    'student_id' => $record['student_id'],
                    'student_name' => $original['student_name'] ?? 'Unknown student',
                    'raw_line' => $original['student_name'] ?? 'Unknown student',
                    'missing' => $missing,
                ];
            }

            $parsedRecords[] = array_merge($original, [
                'student_id' => (int) $record['student_id'],
                'gathering_type' => $draft->gathering_type,
                'batch' => $formattedBatch,
                'venue' => $hasVenue ? $venue : null,
                'day' => $day,
                'status' => $dayProvided && $formattedBatch && $hasVenue ? 'present' : 'no_response',
            ]);
        }

        if ($formatErrors !== [] || $incompleteRecords !== []) {
            return response()->json([
                'status' => 'error',
                'message' => 'Fix the attendance format issues before saving this draft.',
                'format_errors' => $formatErrors,
                'incomplete_records' => $incompleteRecords,
            ], 422);
        }

        DB::transaction(function () use ($draft, $parsedRecords): void {
            $draft->update([
                'parsed_data' => $parsedRecords,
                'matched_count' => collect($parsedRecords)->where('status', 'present')->count(),
            ]);
        });

        $action = $requestedRemovalIds->isNotEmpty()
            ? 'Removed ' . $requestedRemovalIds->count() . ' inactive student(s) and edited attendance draft for ' . $draft->gathering_type
            : "Edited attendance draft for {$draft->gathering_type}";
        $auditLogService->recordAction(
            $action,
            'attendance_drafts',
            (string) $draft->id,
            $request->user()
        );

        $submissionDay = optional($draft->day)->toDateString();
        $students = collect($parsedRecords)->map(fn (array $record) => [
            'student_id' => $record['student_id'],
            'student_name' => $record['student_name'] ?? 'Unknown',
            'status' => $record['status'],
            'batch' => !empty($record['batch'])
                ? \Carbon\Carbon::parse($record['batch'])->format('g:ia')
                : '---',
            'venue' => $record['venue'] ?? '---',
            'day' => $record['day'] ?? $submissionDay ?? '---',
            'day_provided' => !empty($record['day']),
        ])->values();
        $responded = $students->where('status', 'present')->count();

        return response()->json([
            'status' => 'success',
            'message' => 'Attendance draft updated successfully.',
            'data' => [
                'students' => $students,
                'responded_count' => $responded,
                'no_response_count' => $students->count() - $responded,
            ],
        ], 200);
    }

    private function ordinalYear($yearLevel): string
    {
        if (! is_numeric($yearLevel) || (int) $yearLevel < 1) {
            return '';
        }

        $year = (int) $yearLevel;
        $suffix = match (true) {
            $year % 100 >= 11 && $year % 100 <= 13 => 'th',
            $year % 10 === 1 => 'st',
            $year % 10 === 2 => 'nd',
            $year % 10 === 3 => 'rd',
            default => 'th',
        };

        return "{$year}{$suffix} Year";
    }

    /**
     * Submit a draft: Move valid entries to final attendance records and clear/mark draft.
     * POST /api/attendance/drafts/{id}/submit
     */
    // public function submit(Request $request, int $id): JsonResponse
    // {
    //     $draft = AttendanceDraft::where('id', $id)
    //         ->where('user_id', $request->user()->id)
    //         ->where('status', 'draft')
    //         ->first();

    //     if (!$draft) {
    //         return response()->json([
    //             'status'  => 'error',
    //             'message' => 'Draft not found or already processed.',
    //         ], 404);
    //     }

    //     $studentIds = collect($draft->parsed_data)
    //         ->pluck('student_id')
    //         ->filter()
    //         ->unique();
    //     $accessibleStudentCount = Student::whereKey($studentIds)
    //         ->accessibleTo($request->user())
    //         ->count();

    //     if ($accessibleStudentCount !== $studentIds->count()) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'You are not authorized to submit attendance for one or more students.',
    //         ], 403);
    //     }

    //     DB::transaction(function () use ($draft, $request) {
    //         // 1. Insert parsed data into official attendance table (adjust table/model name as needed)
    //         foreach ($draft->parsed_data as $record) {
    //             DB::table('attendance_records')->insert([
    //                 'user_id'      => $request->user()->id,
    //                 'gathering_id' => $draft->gathering_id,
    //                 'student_id'   => $record['student_id'] ?? null,
    //                 'status'       => $record['status'] ?? 'present',
    //                 'created_at'   => now(),
    //                 'updated_at'   => now(),
    //             ]);
    //         }

    //         // 2. Mark draft as submitted
    //         $draft->update(['status' => 'submitted']);

    //         // 3. Update related submission reminder status if applicable
    //         if ($draft->gathering_id) {
    //             Reminder::where('user_id', $request->user()->id)
    //                 ->where('gathering_id', $draft->gathering_id)
    //                 ->where('type', 'submission_deadline')
    //                 ->update(['status' => 'completed']);
    //         }
    //     });

    //     return response()->json([
    //         'status'  => 'success',
    //         'message' => 'Attendance record submitted successfully.',
    //     ], 200);
    // }

        public function destroyMany(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $deleted = AttendanceDraft::whereIn('id', $validated['ids'])
            ->where('user_id', $request->user()->id)
            ->where('status', 'draft')
            ->delete();

        return response()->json([
            'status'  => 'success',
            'message' => "{$deleted} draft(s) deleted.",
            'deleted' => $deleted,
        ], 200);
    }
}

