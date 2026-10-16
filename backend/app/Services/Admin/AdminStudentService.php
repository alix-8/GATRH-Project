<?php

namespace App\Services\Admin;

use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            'programs' => Program::query()
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
        $this->ensureStudentIsUnique($data);

        return DB::transaction(function () use ($data) {
            return Student::query()->create($data);
        });
    }

    public function update(Student $student, array $data): Student
    {
        $this->ensureStudentIsUnique($data, $student);

        return DB::transaction(function () use ($student, $data) {
            $student->fill($data)->save();

            return $student->fresh();
        });
    }

    public function deactivate(Student $student): void
    {
        DB::transaction(function () use ($student): void {
            $student->is_active = false;
            $student->save();
        });
    }

    public function rolloverSchoolYear(string $schoolYear, User $actor): array
    {
        return DB::transaction(function () use ($schoolYear, $actor): array {
            if (DB::table('school_year_rollovers')->where('school_year', $schoolYear)->exists()) {
                throw ValidationException::withMessages([
                    'school_year' => ["The {$schoolYear} school-year rollover has already been applied."],
                ]);
            }

            $students = Student::query()->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
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

            $rolloverId = DB::table('school_year_rollovers')->insertGetId([
                'school_year' => $schoolYear,
                'promoted_students_count' => count($promotedIds),
                'graduated_students_count' => count($graduatedIds),
                'promoted_secretaries_count' => count($promotedSecretaryIds),
                'graduated_secretaries_count' => count($graduatedSecretaryIds),
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

            $this->auditLogService->recordAction(
                "School-year rollover {$schoolYear}: " . count($promotedIds) . ' students and ' . count($promotedSecretaryIds) . ' secretaries promoted; ' . count($graduatedIds) . ' students and ' . count($graduatedSecretaryIds) . ' secretaries graduated',
                'school_year_rollovers',
                (string) $rolloverId,
                $actor,
            );

            return [
                'school_year' => $schoolYear,
                'promoted_students_count' => count($promotedIds),
                'graduated_students_count' => count($graduatedIds),
                'promoted_secretaries_count' => count($promotedSecretaryIds),
                'graduated_secretaries_count' => count($graduatedSecretaryIds),
                'message' => "Rollover complete for {$schoolYear}: " . count($promotedIds) . ' students and ' . count($promotedSecretaryIds) . ' secretaries promoted; ' . count($graduatedIds) . ' students and ' . count($graduatedSecretaryIds) . ' secretaries graduated.',
            ];
        });
    }

    private function getSummary(): array
    {
        return [
            'total' => Student::query()->count(),
            'active' => Student::query()->active()->count(),
            'inactive' => Student::query()->where('is_active', false)->count(),
            'programs' => Program::query()->count(),
        ];
    }

    private function getStudents(array $filters): LengthAwarePaginator
    {
        return Student::query()
            ->withCount('attendanceRecords')
            ->when($filters['program'] ?? null, fn ($query, $program) => $query->where('program', $program))
            ->when($filters['year_level'] ?? null, fn ($query, $yearLevel) => $query->where('year_level', $yearLevel))
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', $filters['is_active']))
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
                'name' => ['A student with this name, program, and year level already exists.'],
            ]);
        }
    }
}
