<?php

namespace App\Http\Controllers\Reminders;

use App\Http\Controllers\Controller;

use App\Models\Reminder;
use App\Models\AttendanceRecord;
use App\Models\Escalation;
use App\Services\Reminders\ReminderCountdownService;
use App\Services\Reminders\GatheringService;
use App\Services\Reminders\ReminderGenerationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ReminderController extends Controller
{
    protected ReminderCountdownService $countdownService;
    protected GatheringService $gatheringService;
    protected ReminderGenerationService $reminderGenerationService;

    public function __construct(
        ReminderCountdownService $countdownService,
        GatheringService $gatheringService,
        ReminderGenerationService $reminderGenerationService
    )
    {
        $this->countdownService = $countdownService;
        $this->gatheringService = $gatheringService;
        $this->reminderGenerationService = $reminderGenerationService;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $userId = $user->id;

        // Catch up for this secretary if the scheduled task ran while the
        // Render instance was asleep or was restarting.
        if ($request->user()->role === 'secretary') {
            $this->reminderGenerationService->generateWeeklyGatheringReminders($request->user());
            $this->reminderGenerationService->spawnSubmissionReminders(7, $userId);
        }

        // Clean up gathering reminders left behind by attendance submissions
        // that were recorded before the submission endpoint began deleting them.
        Reminder::query()
            ->where('user_id', $userId)
            ->where('reminder_type', 'gathering')
            ->whereExists(function ($query) use ($userId) {
                $query->selectRaw('1')
                    ->from('attendance_records')
                    ->where('attendance_records.submitted_by', $userId)
                    ->whereColumn('attendance_records.gathering_type', 'reminders.gathering_type')
                    ->whereColumn('attendance_records.day', 'reminders.gathering_date');
            })
            ->delete();

        // Refresh statuses before listing, so upcoming/needs_action is always accurate
        $this->countdownService->statusSetter($userId);

        $status = $request->query('status');
        $query = $request->user()->reminders()->orderBy('due_date');

        if ($status) {
            $query->where('status', $status);
        }

        $reminders = $query->get();
        $countdowns = $this->countdownService->reminderCountdownFor($reminders);

        $reminders->each(function ($reminder) use ($countdowns) {
            $reminder->countdown_text = $countdowns[$reminder->id] ?? null;
        });

        // Keep each role's alerts aligned with its escalation level and student scope.
        $alertLevel = match ($user->role) {
            'secretary' => 'Level 1',
            'program_head' => 'Level 2',
            'cm_coordinator' => 'Level 3',
            default => null,
        };

        $visibleEscalations = $alertLevel
            ? Escalation::query()
                ->visibleTo($user)
                ->where('escalation_level', $alertLevel)
                ->with('student')
                ->orderByDesc('no_response_count')
                ->get()
            : collect();

        $escalationAlerts = $visibleEscalations->map(function (Escalation $escalation) {
            $student = $escalation->student;
            $programYear = strtoupper(trim($student->program)) . '-' . trim((string) $student->year_level);
            $level = strtolower($escalation->escalation_level);

            return [
                'id' => $escalation->id,
                'student_id' => $student->id,
                'student_name' => $student->name,
                'program' => $student->program,
                'year_level' => $student->year_level,
                'escalation_level' => $escalation->escalation_level,
                'description' => "{$programYear} {$student->name} has reached Escalation {$level} and requires a follow up.",
            ];
        })->values();

        // Get all reminder status totals in one TiDB query instead of three.
        $reminderSummary = Reminder::query()
            ->where('user_id', $userId)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS upcoming, '
                . 'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS needs_action, '
                . 'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS completed',
                ['upcoming', 'needs_action', 'completed']
            )
            ->first();

        return response()->json([
            'status'  => 'success',
            'summary' => [
                'upcoming'     => (int) $reminderSummary->upcoming,
                'needs_action' => (int) $reminderSummary->needs_action,
                'completed'    => (int) $reminderSummary->completed,
                'escalation_count' => $alertLevel
                    ? $escalationAlerts->count()
                    : ($user->role === 'program_secretary'
                        ? 0
                        : Escalation::query()->visibleTo($user)->count()),
            ],
            'data' => $reminders,
            'escalations' => $escalationAlerts,
        ]); 
    }

    /** Role-specific counts displayed above the shared Reminders page. */
    public function roleSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['secretary', 'program_head', 'program_secretary', 'chancellor', 'cm_coordinator'], true), 403, 'Unauthorized. You do not have permission to access this resource.');

        $groups = AttendanceRecord::query()
            ->with('student')
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->get()
            ->groupBy(fn (AttendanceRecord $record) => $record->batch_id
                ?: implode('|', [$record->gathering_type, Carbon::parse($record->day)->toDateString(), $record->submitted_by]));

        $escalationCount = $user->role === 'program_head' // program secretaries can't see escalations, only program heads can
            ? Escalation::query()->visibleTo($user)->count()
            : 0;

        $attendanceCount = $user->role === 'program_head' || $user->role === 'program_secretary'
            ? $groups->filter(fn ($group) => !$group->every(fn ($record) => $record->validation_status === 'validated'))->count()
            : $groups->count();

        return response()->json([
            'role' => $user->role,
            'attendance_count' => $attendanceCount,
            'escalation_count' => $escalationCount,
        ]);
    }

    /**
     * Store a newly created reminder in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:20',
            'description' => 'nullable|string|max:100',
            'custom_type' => 'required|in:submission,gathering,general',
            'gathering_type' => 'required|in:pm,ws,tg',
            'due_date'    => 'required|date|after:now',
            'status'      => 'nullable|in:upcoming,needs_action,completed',
        ]);

        $reminder = Reminder::create([
             'user_id'       => $request->user()->id,
            'reminder_type' => 'custom',
            'custom_type'   => $validated['custom_type'],
            'gathering_type' => $validated['gathering_type'],
            'title'         => $validated['title'],
            'description'   => $validated['description'] ?? null,
            'due_date'      => $validated['due_date'],
            'status'        => $validated['status'] ?? 'upcoming',
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Reminder created successfully.',
            'data'    => $reminder,
        ], 201);
    }

    /**
     * Display the specified reminder.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $reminder = Reminder::find($id);

        if (!$reminder) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Reminder not found',
            ], 404);
        }

        // access $reminder->user_id
        if ($reminder->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $reminder,
            'countdown' => app(ReminderCountdownService::class)->reminderCountdown($request->user()->id),
        ], 200);
    }

    /**
     * Update the specified reminder.
     */
    public function update(Request $request, Reminder $reminder): JsonResponse
    {
        // Enforce ownership check
        if ($reminder->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        if ($reminder->status === 'completed') {
            return response()->json([
                'status' => 'error',
                'message' => 'Completed reminders cannot be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'title'       => 'sometimes|required|string|max:20',
            'description' => 'nullable|string|max:100',
            'custom_type' => 'sometimes|required|in:submission,gathering,general',
            'gathering_type' => 'sometimes|required|in:pm,ws,tg',
            'due_date'    => 'sometimes|required|date|after_or_equal:today',
            'status'      => 'sometimes|required|in:upcoming,needs_action,completed',
        ]);

        $reminder->update($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Reminder updated successfully.',
            'data'    => $reminder,
        ], 200);
    }

    /**
     * Store a newly created gathering reminder in storage.
     */
    public function storeGathering(Request $request)
    {
        $validated = $request->validate([
            'gathering_type' => 'required|in:pm,ws,tg',
            'program'        => 'required|string',
            'year_level'     => 'required|integer',
            'gathering_date' => 'nullable|date|required_if:gathering_type',
        ]);

        $gatheringDate = $validated['gathering_date'] ?? $this->gatheringService->nextDate($validated['gathering_type']);

        if (! $this->gatheringService->isScheduledDay($validated['gathering_type'], Carbon::parse($gatheringDate))) {
            return response()->json([
                'status'  => 'error',
                'message' => sprintf('%s reminders can only be scheduled on %s.',
                    $this->gatheringService->label($validated['gathering_type']),
                    $this->gatheringService->scheduledDayNames($validated['gathering_type'])
                ),
            ], 422);
        }

        $reminder = Reminder::create([
            'user_id'        => $request->user()->id,
            'reminder_type'  => 'gathering',
            'gathering_type' => $validated['gathering_type'],
            'program'        => strtoupper($validated['program']),
            'year_level'     => $validated['year_level'],
            'gathering_date' => $gatheringDate,
            'title'          => ucfirst($this->gatheringService->label($validated['gathering_type'])),
            'description'    => strtoupper($validated['program']) . '-' . $validated['year_level'],
            'due_date'       => $gatheringDate,
            'status'         => 'upcoming',
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Gathering reminder created.',
            'data'    => $reminder,
        ], 201);
    }

    public function markCompleted(Request $request, $id)
    {
        $reminder = $request->user()->reminders()->findOrFail($id);

        $reminder->update([
            'status'       => 'completed',
            'updated_at' => now(),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Reminder marked as completed.',
            'data'    => $reminder,
        ]);
    }

    /**
     * Remove the specified reminder from storage.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $reminder = Reminder::find($id);

        if (!$reminder) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Reminder not found',
            ], 404);
        }

        if ($reminder->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        if ($reminder->status === 'completed') {
            return response()->json([
                'status' => 'error',
                'message' => 'Completed reminders cannot be deleted.',
            ], 422);
        }

        $reminder->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Reminder deleted successfully.',
        ], 200);
    }
}
