<?php

namespace App\Services;

use App\Models\Program;
use App\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminStudentService
{
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
