<?php

namespace App\Services\Admin;

use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AdminProgramService
{
    public function getPageData(array $filters): array
    {
        return [
            'summary' => $this->getSummary(),
            'programs' => $this->getPrograms($filters),
            'program_options' => Program::query()
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'year_levels' => Student::query()
                ->select('year_level')
                ->distinct()
                ->orderBy('year_level')
                ->pluck('year_level'),
            'program_head_options' => User::query()
                ->where('role', 'program_head')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ];
    }

    public function getProgram(Program $program): Program
    {
        return $this->loadProgramDetails($program);
    }

    public function create(array $data): Program
    {
        return DB::transaction(function () use ($data) {
            $programHeadIds = $data['program_head_ids'] ?? [];
            unset($data['program_head_ids']);
            $data['code'] = strtoupper(trim($data['code']));

            $program = Program::query()->create($data);
            $program->users()->sync($programHeadIds);

            if (! $program->is_active) {
                $this->deactivateProgramMembers($program);
            }

            return $this->loadProgramDetails($program);
        });
    }

    public function update(Program $program, array $data): Program
    {
        return DB::transaction(function () use ($program, $data) {
            $shouldUpdateProgramHeads = array_key_exists('program_head_ids', $data);
            $programHeadIds = $data['program_head_ids'] ?? null;
            unset($data['program_head_ids']);

            $program->fill($data)->save();

            if ($shouldUpdateProgramHeads) {
                $otherUserIds = $program->users()
                    ->where('role', '!=', 'program_head')
                    ->pluck('users.id')
                    ->all();

                $program->users()->sync(array_values(array_unique([
                    ...$otherUserIds,
                    ...($programHeadIds ?? []),
                ])));
            }

            if (array_key_exists('is_active', $data) && ! $program->is_active) {
                $this->deactivateProgramMembers($program);
            }

            return $this->loadProgramDetails($program->fresh());
        });
    }

    private function deactivateProgramMembers(Program $program): void
    {
        User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($program) {
                $query->whereHas('programs', fn ($programQuery) => $programQuery->whereKey($program->id))
                    ->orWhere('program', $program->code);
            })
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    if ($this->userHasAnActiveProgram($user)) {
                        continue;
                    }

                    $user->update(['is_active' => false]);
                    $user->tokens()->delete();
                }
            });

        Student::query()
            ->where('program', $program->code)
            ->where('is_active', true)
            ->chunkById(100, function ($students) {
                foreach ($students as $student) {
                    $student->update(['is_active' => false]);
                }
        });
    }

    private function userHasAnActiveProgram(User $user): bool
    {
        if ($user->programs()->where('programs.is_active', true)->exists()) {
            return true;
        }

        if (! $user->program) {
            return false;
        }

        return Program::query()
            ->where('code', $user->program)
            ->where('is_active', true)
            ->exists();
    }

    private function getSummary(): array
    {
        return [
            'total' => Program::query()->count(),
            'program_heads' => User::query()
                ->where('role', 'program_head')
                ->where('is_active', true)
                ->whereHas('programs', fn ($query) => $query->where('programs.is_active', true))
                ->distinct()
                ->count('users.id'),
            'active' => Program::query()->where('is_active', true)->count(),
            'inactive' => Program::query()->where('is_active', false)->count(),
        ];
    }

    private function getPrograms(array $filters): LengthAwarePaginator
    {
        return Program::query()
            ->with(['programHeads' => fn ($query) => $query
                ->where('users.is_active', true)
                ->select('users.id', 'users.name', 'users.email')])
            ->withCount(['students as students_count' => fn ($query) => $query->where('is_active', true)])
            ->when($filters['program'] ?? null, fn ($query, $code) => $query->where('code', $code))
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', $filters['is_active']))
            ->when($filters['year_level'] ?? null, fn ($query, $yearLevel) => $query->whereHas(
                'students',
                fn ($studentQuery) => $studentQuery->where('year_level', $yearLevel)
            ))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($programQuery) use ($search) {
                    $programQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->orderBy('code')
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();
    }

    private function loadProgramDetails(Program $program): Program
    {
        return $program
            ->load(['programHeads' => fn ($query) => $query
                ->where('users.is_active', true)
                ->select('users.id', 'users.name', 'users.email')])
            ->loadCount(['students as students_count' => fn ($query) => $query->where('is_active', true)]);
    }
}
