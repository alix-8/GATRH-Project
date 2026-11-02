<?php

namespace App\Services\Admin;

use App\Models\Program;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AdminUserService
{
    private const ROLE_LABELS = [
        'chancellor' => 'Chancellor',
        'cm_coordinator' => 'CM Coordinator',
        'program_head' => 'Program Head',
        'program_secretary' => 'Program Secretary',
        'secretary' => 'Secretary',
        'admin' => 'Administrator',
    ];

    public function getPageData(array $filters): array
    {
        return [
            'summary' => $this->getSummary(),
            'users' => $this->getUsers($filters)->through(fn (User $user) => $this->presentUser($user)),
            'programs' => Program::query()->active()
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'roles' => $this->getRoleOptions(),
        ];
    }

    /** Return only the account fields required by the admin UI. */
    public function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'contact_number' => $user->contact_number,
            'program' => $user->program,
            'year_level' => $user->year_level,
            'is_active' => $user->is_active,
            'programs' => $user->programs->map(fn (Program $program) => [
                'id' => $program->id,
                'code' => $program->code,
                'name' => $program->name,
            ])->values()->all(),
        ];
    }

    public function create(array $data): array
    {
        $temporaryPassword = $this->generateTemporaryPassword();

        $user = DB::transaction(function () use ($data, $temporaryPassword) {
            $programIds = $this->resolveProgramIds($data);
            $data['program'] = $this->resolvePrimaryProgram($data, $programIds);
            unset($data['program_ids']);
            $data['password'] = $temporaryPassword;

            $user = User::query()->create($data);
            $user->programs()->sync($programIds);

            return $user->load('programs:id,code,name');
        });

        try {
            Mail::send([
                'html' => 'emails.temporary-password',
                'text' => 'emails.temporary-password-text',
            ], [
                'name' => $user->name,
                'temporaryPassword' => $temporaryPassword,
                'appUrl' => rtrim(config('app.frontend_url'), '/'),
            ], function ($message) use ($user): void {
                $message->to($user->email)->subject('Your temporary GATRH account password');
            });
        } catch (\Throwable $exception) {
            $user->programs()->detach();
            $user->delete();
            report($exception);

            return [
                'user' => null,
                'temporary_password_email_sent' => false,
            ];
        }

        return [
            'user' => $user,
            'temporary_password_email_sent' => true,
        ];
    }

    public function update(User $user, array $data, User $actor): User
    {
        $this->ensureActorCanUpdateAccount($user, $actor, $data);

        return DB::transaction(function () use ($user, $data) {
            $programIds = $this->resolveProgramIds($data, $user);

            if (array_key_exists('program', $data) || array_key_exists('program_ids', $data)) {
                $data['program'] = $this->resolvePrimaryProgram($data, $programIds, $user);
            }

            if (($data['is_active'] ?? $user->is_active)
                && in_array($data['role'] ?? $user->role, ['secretary', 'program_head', 'program_secretary'], true)
                && ! Program::query()->active()->where(fn ($programs) => $programs
                    ->whereIn('id', $programIds)->orWhere('code', $data['program'] ?? $user->program))->exists()) {
                throw ValidationException::withMessages(['program' => ['An active account must be assigned to an active program.']]);
            }

            unset($data['program_ids']);
            $user->fill($data)->save();
            $user->programs()->sync($programIds);

            if (! $user->is_active) {
                $user->tokens()->delete();
            }

            return $user->fresh()->load('programs:id,code,name');
        });
    }

    private function getSummary(): array
    {
        $counts = User::query()
            ->selectRaw('role, COUNT(*) as total')
            ->where('is_active', true)
            ->groupBy('role')
            ->pluck('total', 'role');

        return [
            'total' => User::query()->count(),
            'active' => User::query()->where('is_active', true)->count(),
            'inactive' => User::query()->where('is_active', false)->count(),
            'by_role' => collect(self::ROLE_LABELS)
                ->map(fn (string $label, string $role) => [
                    'role' => $role,
                    'label' => $label,
                    'count' => (int) ($counts[$role] ?? 0),
                ])
                ->values(),
        ];
    }

    private function getUsers(array $filters): LengthAwarePaginator
    {
        return User::query()
            ->with(['programs' => fn ($programs) => $programs
                ->active()
                ->select(['programs.id', 'programs.code', 'programs.name'])])
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when($filters['program'] ?? null, fn ($query, $program) => $query->where(function ($programQuery) use ($program) {
                $programQuery->where('program', $program)
                    ->orWhereHas('programs', fn ($assignedProgram) => $assignedProgram->where('code', $program));
            }))
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', $filters['is_active']))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
            if (ctype_digit($search)) {
                        $userQuery->orWhere('id', (int) $search);
                    }

                });
            })
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();
    }

    private function getRoleOptions(): array
    {
        return collect(self::ROLE_LABELS)
            ->map(fn (string $label, string $role) => [
                'value' => $role,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    private function resolveProgramIds(array $data, ?User $user = null): array
    {
        if (array_key_exists('program_ids', $data)) {
            return $data['program_ids'] ?? [];
        }

        if ($user && ! array_key_exists('program', $data)) {
            return $user->programs()->pluck('program_id')->all();
        }

        if (array_key_exists('program', $data) && ! $data['program']) {
            return [];
        }

        $programCode = $data['program'] ?? $user?->program;
        if (! $programCode) {
            return $user?->programs()->pluck('program_id')->all() ?? [];
        }

        $programId = Program::query()->where('code', $programCode)->value('id');

        return $programId ? [(int) $programId] : [];
    }

    private function resolvePrimaryProgram(array $data, array $programIds, ?User $user = null): ?string
    {
        if (array_key_exists('program', $data)) {
            return $data['program'];
        }

        if (array_key_exists('program_ids', $data) && $programIds === []) {
            return null;
        }

        if ($programIds !== []) {
            return Program::query()->whereKey($programIds[0])->value('code');
        }

        return $user?->program;
    }

    private function ensureActorCanUpdateAccount(User $user, User $actor, array $data): void
    {
        $willDeactivate = array_key_exists('is_active', $data) && ! $data['is_active'];
        $willRemoveAdminRole = ($data['role'] ?? $user->role) !== 'admin';

        if ($user->is($actor) && ($willDeactivate || $willRemoveAdminRole)) {
            throw ValidationException::withMessages([
                'user' => ['You cannot deactivate or remove the administrator role from your own account.'],
            ]);
        }

        $losesAdminAccess = $user->role === 'admin'
            && $user->is_active
            && ($willDeactivate || $willRemoveAdminRole);

        if ($losesAdminAccess && User::query()->where('role', 'admin')->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages([
                'user' => ['The last active administrator account must remain active.'],
            ]);
        }
    }

    private function generateTemporaryPassword(): string
    {
        $characterGroups = [
            'abcdefghijkmnopqrstuvwxyz',
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            '23456789',
            '!@#$%&*+-=?',
        ];

        $characters = [];
        foreach ($characterGroups as $group) {
            $characters[] = $group[random_int(0, strlen($group) - 1)];
        }

        $allCharacters = implode('', $characterGroups);
        for ($index = 0; $index < 12; $index++) {
            $characters[] = $allCharacters[random_int(0, strlen($allCharacters) - 1)];
        }

        for ($index = count($characters) - 1; $index > 0; $index--) {
            $swapIndex = random_int(0, $index);
            [$characters[$index], $characters[$swapIndex]] = [
                $characters[$swapIndex],
                $characters[$index],
            ];
        }

        return implode('', $characters);
    }

}
