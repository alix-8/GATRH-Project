<?php

namespace App\Services\Admin;

use App\Models\Program;
use App\Models\Student;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;

class AdminStudentService
{
    public function __construct(private AuditLogService $auditLogService)
    {
    }

    public function getPageData(array $filters): array
    {
        return [
            'summary' => $this->getSummary(),
            'students' => $this->getStudents($filters),
            'programs' => Program::query()->active()
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'year_levels' => Student::query()
                ->select('year_level')
                ->distinct()
                ->orderBy('year_level')
                ->pluck('year_level'),
        ];
    }

    public function create(array $data): Student
    {
        $this->assertActiveProgram($data['program']);
        $this->ensureStudentIsUnique($data);
        $data['inactive_reason'] = null;

        return DB::transaction(function () use ($data) {
            return Student::query()->create($data);
        });
    }

    public function update(Student $student, array $data): Student
    {
        if (($data['program'] ?? $student->program) !== $student->program
            || ($data['is_active'] ?? $student->is_active)) {
            $this->assertActiveProgram($data['program'] ?? $student->program);
        }
        $this->ensureStudentIsUnique($data, $student);

        if (array_key_exists('is_active', $data)) {
            $data['inactive_reason'] = $data['is_active'] ? null : 'manual';
        }

        return DB::transaction(function () use ($student, $data) {
            $student->fill($data)->save();

            return $student->fresh();
        });
    }

    public function deactivate(Student $student): void
    {
        DB::transaction(function () use ($student): void {
            $student->is_active = false;
            $student->inactive_reason = 'manual';
            $student->save();
        });
    }

    public function previewSchoolYearRollover(string $schoolYear): array
    {
        $settings = SystemSetting::query()->findOrFail(1);
        $this->assertCurrentSchoolYear($schoolYear, $settings->academic_year);

        $students = Student::query()->active()->get(['name', 'program', 'year_level']);
        $secretaries = User::query()->where('role', 'secretary')->where('is_active', true)->get(['email', 'program', 'year_level']);
        if ($students->isEmpty() && $secretaries->isEmpty()) {
            throw ValidationException::withMessages(['students' => ['There are no active students or secretary accounts to roll over.']]);
        }
        $destinations = [];
        $studentGroups = $students->groupBy(fn (Student $student) => strtoupper((string) $student->program) . '|' . $student->year_level);
        $graduatedStudents = 0;
        $promotedStudents = 0;
        foreach ($students as $student) {
            $program = strtoupper(trim((string) $student->program));
            $maximumYear = $program === 'ACT' ? 2 : 4;
            $year = (string) $student->year_level;
            if (! ctype_digit($year) || (int) $year < 1 || (int) $year > $maximumYear) {
                throw ValidationException::withMessages(['students' => ["{$student->name} ({$student->program}, Year {$student->year_level}) has an invalid year level. Fix the record before running the rollover."]]);
            }
            $graduates = (int) $year === $maximumYear;
            $nextYear = $graduates ? $year : (string) ((int) $year + 1);
            $key = strtolower(trim($student->name)) . '|' . $program . '|' . $nextYear;
            if (isset($destinations[$key])) {
                throw ValidationException::withMessages(['students' => ["The rollover would create duplicate student records in {$student->program} Year {$nextYear}. Resolve duplicate names before continuing."]]);
            }
            $destinations[$key] = true;
            if ($graduates) $graduatedStudents++;
            else $promotedStudents++;
        }
        $inactiveConflict = Student::query()->where('is_active', false)->get(['name', 'program', 'year_level'])
            ->first(fn (Student $student) => isset($destinations[strtolower(trim($student->name)) . '|' . strtoupper(trim((string) $student->program)) . '|' . (string) $student->year_level]));
        if ($inactiveConflict) {
            throw ValidationException::withMessages(['students' => ["The rollover conflicts with an existing inactive student in {$inactiveConflict->program} Year {$inactiveConflict->year_level}. Resolve duplicate names before continuing."]]);
        }
        $graduatedSecretaries = 0;
        $promotedSecretaries = 0;
        foreach ($secretaries as $secretary) {
            $program = strtoupper(trim((string) $secretary->program));
            $maximumYear = $program === 'ACT' ? 2 : 4;
            $year = (string) $secretary->year_level;
            if (! ctype_digit($year) || (int) $year < 1 || (int) $year > $maximumYear) {
                throw ValidationException::withMessages(['secretaries' => ["Secretary account {$secretary->email} ({$secretary->program}, Year {$secretary->year_level}) has an invalid year level. Fix the account before running the rollover."]]);
            }
            if ((int) $year === $maximumYear) $graduatedSecretaries++;
            else $promotedSecretaries++;
        }

        return [
            'school_year' => $schoolYear,
            'next_school_year' => $this->nextSchoolYear($schoolYear),
            'preview_token' => $this->rolloverPreviewToken($schoolYear),
            'promoted_students_count' => $promotedStudents,
            'graduated_students_count' => $graduatedStudents,
            'promoted_secretaries_count' => $promotedSecretaries,
            'graduated_secretaries_count' => $graduatedSecretaries,
            'students_by_program_year' => $studentGroups->map(function ($group, string $key) {
                [$program, $year] = explode('|', $key, 2);
                $maximumYear = $program === 'ACT' ? 2 : 4;
                return [
                    'program' => $program,
                    'year_level' => (int) $year,
                    'active_count' => $group->count(),
                    'will_graduate' => (int) $year === $maximumYear,
                ];
            })->values()->all(),
        ];
    }

    public function schoolYearRolloverStatus(): array
    {
        $settings = SystemSetting::query()->findOrFail(1);
        $rollover = DB::table('school_year_rollovers')->orderByDesc('id')->first();
        $canUndo = $rollover
            && $rollover->undone_at === null
            && $rollover->undo_expires_at !== null
            && Carbon::parse($rollover->undo_expires_at)->isFuture()
            && $settings->academic_year === $this->nextSchoolYear($rollover->school_year);
        $undoExpiresAt = $rollover?->undo_expires_at
            ? Carbon::parse($rollover->undo_expires_at)
            : null;

        return [
            'current_school_year' => $settings->academic_year,
            'latest_rollover' => $rollover ? [
                'id' => $rollover->id,
                'school_year' => $rollover->school_year,
                'undo_expires_at' => $undoExpiresAt?->toIso8601String(),
                'can_undo' => (bool) $canUndo,
                'remaining_seconds' => $canUndo ? max(0, Carbon::parse($rollover->undo_expires_at)->diffInSeconds(now())) : 0,
            ] : null,
        ];
    }

    public function rolloverSchoolYear(string $schoolYear, User $actor, string $previewToken): array
    {
        return DB::transaction(function () use ($schoolYear, $actor, $previewToken): array {
            $settings = SystemSetting::query()->lockForUpdate()->findOrFail(1);
            $this->assertCurrentSchoolYear($schoolYear, $settings->academic_year);
            $currentPreviewToken = $this->rolloverPreviewToken($schoolYear, true);
            if (! hash_equals($currentPreviewToken, $previewToken)) {
                throw ValidationException::withMessages(['rollover' => ['Student or secretary records changed after the preview. Refresh the preview before confirming.']]);
            }
            if (DB::table('school_year_rollovers')->where('school_year', $schoolYear)->whereNull('undone_at')->exists()) {
                throw ValidationException::withMessages([
                    'school_year' => ["The {$schoolYear} school-year rollover has already been applied."],
                ]);
            }

            $students = Student::query()->active()->orderBy('id')->lockForUpdate()->get();
            $secretaries = User::query()
                ->where('role', 'secretary')
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($students->isEmpty() && $secretaries->isEmpty()) {
                throw ValidationException::withMessages([
                    'students' => ['There are no active students or secretary accounts to roll over.'],
                ]);
            }

            $destinations = [];
            $promotedIds = [];
            $graduatedIds = [];
            $promotedSecretaryIds = [];
            $graduatedSecretaryIds = [];
            foreach ($students as $student) {
                $programCode = strtoupper(trim((string) $student->program));
                $maximumYear = $programCode === 'ACT' ? 2 : 4;
                $currentYear = (string) $student->year_level;

                if (! ctype_digit($currentYear) || (int) $currentYear < 1 || (int) $currentYear > $maximumYear) {
                    throw ValidationException::withMessages([
                        'students' => ["{$student->name} ({$student->program}, Year {$student->year_level}) has an invalid year level. Fix the record before running the rollover."],
                    ]);
                }

                $graduates = (int) $currentYear === $maximumYear;
                $nextYear = $graduates ? $currentYear : (string) ((int) $currentYear + 1);
                $destinationKey = strtolower(trim($student->name)) . '|' . $programCode . '|' . $nextYear;

                if (isset($destinations[$destinationKey])) {
                    throw ValidationException::withMessages([
                        'students' => ["The rollover would create duplicate student records in {$student->program} Year {$nextYear}. Resolve duplicate names before continuing."],
                    ]);
                }

                $destinations[$destinationKey] = $student->id;
                if ($graduates) {
                    $graduatedIds[] = $student->id;
                } else {
                    $promotedIds[] = $student->id;
                }
            }

            foreach ($secretaries as $secretary) {
                $programCode = strtoupper(trim((string) $secretary->program));
                $maximumYear = $programCode === 'ACT' ? 2 : 4;
                $currentYear = (string) $secretary->year_level;

                if (! ctype_digit($currentYear) || (int) $currentYear < 1 || (int) $currentYear > $maximumYear) {
                    throw ValidationException::withMessages([
                        'secretaries' => ["Secretary account {$secretary->email} ({$secretary->program}, Year {$secretary->year_level}) has an invalid year level. Fix the account before running the rollover."],
                    ]);
                }

                if ((int) $currentYear === $maximumYear) {
                    $graduatedSecretaryIds[] = $secretary->id;
                } else {
                    $promotedSecretaryIds[] = $secretary->id;
                }
            }

            $allStudents = Student::query()->lockForUpdate()->get(['id', 'name', 'program', 'year_level', 'is_active']);
            foreach ($allStudents as $student) {
                if ($student->is_active) {
                    continue;
                }

                $key = strtolower(trim($student->name)) . '|' . strtoupper(trim($student->program)) . '|' . (string) $student->year_level;
                if (isset($destinations[$key])) {
                    throw ValidationException::withMessages([
                        'students' => ["The rollover conflicts with an existing inactive student in {$student->program} Year {$student->year_level}. Resolve duplicate names before continuing."],
                    ]);
                }
            }

            $snapshotStudents = $students->map(function (Student $student) use ($graduatedIds): array {
                $graduates = in_array($student->id, $graduatedIds, true);
                return [
                    'id' => $student->id,
                    'before' => [
                        'year_level' => $student->year_level,
                        'is_active' => (bool) $student->is_active,
                        'inactive_reason' => $student->inactive_reason,
                    ],
                    'after' => [
                        'year_level' => $graduates ? $student->year_level : (string) ((int) $student->year_level + 1),
                        'is_active' => ! $graduates,
                        'inactive_reason' => $graduates ? 'graduated' : null,
                    ],
                ];
            })->all();
            $snapshotSecretaries = $secretaries->map(function (User $secretary) use ($graduatedSecretaryIds): array {
                $graduates = in_array($secretary->id, $graduatedSecretaryIds, true);
                return [
                    'id' => $secretary->id,
                    'before' => ['year_level' => $secretary->year_level, 'is_active' => (bool) $secretary->is_active],
                    'after' => [
                        'year_level' => $graduates ? $secretary->year_level : (string) ((int) $secretary->year_level + 1),
                        'is_active' => ! $graduates,
                    ],
                ];
            })->all();
            $nextSchoolYear = $this->nextSchoolYear($schoolYear);
            $undoExpiresAt = now()->addMinutes(5);

            $rolloverId = DB::table('school_year_rollovers')->insertGetId([
                'school_year' => $schoolYear,
                'promoted_students_count' => count($promotedIds),
                'graduated_students_count' => count($graduatedIds),
                'promoted_secretaries_count' => count($promotedSecretaryIds),
                'graduated_secretaries_count' => count($graduatedSecretaryIds),
                'snapshot' => json_encode([
                    'students' => $snapshotStudents,
                    'secretaries' => $snapshotSecretaries,
                    'next_academic_year' => $nextSchoolYear,
                ], JSON_THROW_ON_ERROR),
                'undo_expires_at' => $undoExpiresAt,
                'performed_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Stage promotions at unique temporary year levels to avoid collisions
            // with students moving into a year level in the same transaction.
            foreach ($promotedIds as $studentId) {
                $temporaryLevel = '__roll_' . bin2hex(random_bytes(8));
                DB::table('students')->where('id', $studentId)->update(['year_level' => $temporaryLevel]);
            }

            foreach ($students as $student) {
                if (in_array($student->id, $graduatedIds, true)) {
                    $student->is_active = false;
                    $student->inactive_reason = 'graduated';
                    $student->save();
                    continue;
                }

                $student->year_level = (string) ((int) $student->year_level + 1);
                $student->save();
            }

            foreach ($secretaries as $secretary) {
                if (in_array($secretary->id, $graduatedSecretaryIds, true)) {
                    $secretary->is_active = false;
                    $secretary->save();
                    $secretary->tokens()->delete();
                    continue;
                }

                $secretary->year_level = (string) ((int) $secretary->year_level + 1);
                $secretary->save();
            }

            $settings->academic_year = $nextSchoolYear;
            $settings->updated_by = $actor->id;
            $settings->save();

            $this->auditLogService->recordAction(
                "School-year rollover {$schoolYear}: " . count($promotedIds) . ' students and ' . count($promotedSecretaryIds) . ' secretaries promoted; ' . count($graduatedIds) . ' students and ' . count($graduatedSecretaryIds) . ' secretaries graduated',
                'school_year_rollovers',
                (string) $rolloverId,
                $actor,
            );

            return [
                'rollover_id' => $rolloverId,
                'school_year' => $schoolYear,
                'next_school_year' => $nextSchoolYear,
                'undo_expires_at' => $undoExpiresAt->toIso8601String(),
                'promoted_students_count' => count($promotedIds),
                'graduated_students_count' => count($graduatedIds),
                'promoted_secretaries_count' => count($promotedSecretaryIds),
                'graduated_secretaries_count' => count($graduatedSecretaryIds),
                'message' => "Rollover complete for {$schoolYear}: " . count($promotedIds) . ' students and ' . count($promotedSecretaryIds) . ' secretaries promoted; ' . count($graduatedIds) . ' students and ' . count($graduatedSecretaryIds) . ' secretaries graduated.',
            ];
        });
    }

    public function undoSchoolYearRollover(int $rolloverId, User $actor): array
    {
        return DB::transaction(function () use ($rolloverId, $actor): array {
            $rollover = DB::table('school_year_rollovers')->where('id', $rolloverId)->lockForUpdate()->first();
            if (! $rollover || $rollover->undone_at !== null) {
                throw ValidationException::withMessages(['rollover' => ['This rollover is no longer available to undo.']]);
            }
            if (! $rollover->undo_expires_at || Carbon::parse($rollover->undo_expires_at)->isPast()) {
                throw ValidationException::withMessages(['rollover' => ['The five-minute undo period has expired.']]);
            }

            $snapshot = json_decode($rollover->snapshot ?? '', true);
            if (! is_array($snapshot) || ! isset($snapshot['students'], $snapshot['secretaries'], $snapshot['next_academic_year'])) {
                throw ValidationException::withMessages(['rollover' => ['This rollover does not have a restorable snapshot.']]);
            }
            $settings = SystemSetting::query()->lockForUpdate()->findOrFail(1);
            if ($settings->academic_year !== $snapshot['next_academic_year']) {
                throw ValidationException::withMessages(['rollover' => ['The academic year has changed since this rollover, so it cannot be safely undone.']]);
            }

            foreach ($snapshot['students'] as $item) {
                $student = Student::query()->lockForUpdate()->find($item['id']);
                $hasInactiveReason = array_key_exists('inactive_reason', $item['after']);
                if (! $student
                    || (string) $student->year_level !== (string) $item['after']['year_level']
                    || (bool) $student->is_active !== (bool) $item['after']['is_active']
                    || ($hasInactiveReason && ($student->inactive_reason ?? null) !== $item['after']['inactive_reason'])) {
                    throw ValidationException::withMessages(['rollover' => ['A student changed after the rollover. Resolve the later change before trying to undo.']]);
                }
            }
            foreach ($snapshot['secretaries'] as $item) {
                $secretary = User::query()->lockForUpdate()->find($item['id']);
                if (! $secretary || (string) $secretary->year_level !== (string) $item['after']['year_level'] || (bool) $secretary->is_active !== (bool) $item['after']['is_active']) {
                    throw ValidationException::withMessages(['rollover' => ['A secretary account changed after the rollover. Resolve the later change before trying to undo.']]);
                }
            }

            foreach ($snapshot['students'] as $item) {
                DB::table('students')->where('id', $item['id'])->update(['year_level' => '__undo_' . bin2hex(random_bytes(8))]);
            }
            foreach ($snapshot['students'] as $item) {
                $student = Student::query()->findOrFail($item['id']);
                $student->fill(array_merge(['inactive_reason' => null], $item['before']));
                $student->rememberRosterStatusChange();
                $values = $item['before'];
                if ($student->isDirty('roster_history')) {
                    $values['roster_history'] = json_encode($student->roster_history, JSON_THROW_ON_ERROR);
                }
                DB::table('students')->where('id', $item['id'])->update($values);
            }
            foreach ($snapshot['secretaries'] as $item) {
                DB::table('users')->where('id', $item['id'])->update($item['before']);
            }

            $settings->academic_year = $rollover->school_year;
            $settings->updated_by = $actor->id;
            $settings->save();
            DB::table('school_year_rollovers')->where('id', $rolloverId)->update([
                'undone_at' => now(),
                'updated_at' => now(),
            ]);
            $this->auditLogService->recordAction(
                "Undid school-year rollover {$rollover->school_year}",
                'school_year_rollovers',
                (string) $rolloverId,
                $actor,
            );

            return [
                'message' => "Rollover for {$rollover->school_year} was undone.",
                'current_school_year' => $settings->academic_year,
            ];
        });
    }

    private function assertCurrentSchoolYear(string $schoolYear, ?string $currentSchoolYear): void
    {
        if (! preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $matches) || (int) $matches[2] !== (int) $matches[1] + 1) {
            throw ValidationException::withMessages(['school_year' => ['The school year must use consecutive years.']]);
        }
        if ($schoolYear !== $currentSchoolYear) {
            throw ValidationException::withMessages(['school_year' => ['Only the current academic year can be rolled over.']]);
        }
    }

    private function nextSchoolYear(string $schoolYear): string
    {
        [$start, $end] = array_map('intval', explode('-', $schoolYear));
        return ($start + 1) . '-' . ($end + 1);
    }

    private function rolloverPreviewToken(string $schoolYear, bool $lock = false): string
    {
        $studentsQuery = Student::query()->orderBy('id');
        $secretariesQuery = User::query()->where('role', 'secretary')->where('is_active', true)->orderBy('id');
        if ($lock) {
            $studentsQuery->lockForUpdate();
            $secretariesQuery->lockForUpdate();
        }

        $students = $studentsQuery->get(['id', 'name', 'program', 'year_level', 'is_active', 'inactive_reason'])
            ->map(fn (Student $student) => [
                $student->id,
                $student->name,
                $student->program,
                (string) $student->year_level,
                (bool) $student->is_active,
                $student->inactive_reason,
            ])->all();
        $secretaries = $secretariesQuery->get(['id', 'email', 'program', 'year_level', 'is_active'])
            ->map(fn (User $secretary) => [
                $secretary->id,
                $secretary->email,
                $secretary->program,
                (string) $secretary->year_level,
                (bool) $secretary->is_active,
            ])->all();

        return hash('sha256', json_encode([
            'school_year' => $schoolYear,
            'students' => $students,
            'secretaries' => $secretaries,
        ], JSON_THROW_ON_ERROR));
    }

    private function getSummary(): array
    {
        return [
            'total' => Student::query()->notGraduated()->count(),
            'active' => Student::query()->active()->count(),
            'inactive' => Student::query()->where('is_active', false)->notGraduated()->count(),
            'graduates' => Student::query()->where('inactive_reason', 'graduated')->count(),
            'programs' => Program::query()->active()->count(),
        ];
    }

    private function getStudents(array $filters): LengthAwarePaginator
    {
        return Student::query()
            ->withCount('attendanceRecords')
            ->when($filters['program'] ?? null, fn ($query, $program) => $query->where('program', $program))
            ->when($filters['year_level'] ?? null, fn ($query, $yearLevel) => $query->where('year_level', $yearLevel))
            ->when(
                ($filters['status'] ?? null) === 'graduated',
                fn ($query) => $query->where('inactive_reason', 'graduated'),
                fn ($query) => ($filters['is_active'] ?? true)
                    ? $query->active()
                    : $query->where('is_active', false)->notGraduated()
            )
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($studentQuery) use ($search) {
                    $studentQuery->where('name', 'like', "%{$search}%");

                    if (ctype_digit($search)) {
                        $studentQuery->orWhere('id', (int) $search);
                    }
                });
            })
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();
    }

    private function assertActiveProgram(string $program): void
    {
        if (! Program::query()->active()->where('code', $program)->exists()) {
            throw ValidationException::withMessages(['program' => ['Select an active program.']]);
        }
    }

    private function ensureStudentIsUnique(array $data, ?Student $student = null): void
    {
        $name = $data['name'] ?? $student?->name;
        $program = $data['program'] ?? $student?->program;
        $yearLevel = $data['year_level'] ?? $student?->year_level;

        $duplicate = Student::query()
            ->where('name', $name)
            ->where('program', $program)
            ->where('year_level', $yearLevel)
            ->when($student, fn ($query) => $query->where('id', '!=', $student->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => [sprintf(
                    'Student "%s" already exists in %s Year %s.',
                    $name,
                    $program,
                    $yearLevel
                )],
            ]);
        }
    }
}
