<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminUserController extends Controller
{
    public function __construct(private AdminUserService $userService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(User::ROLES)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if (isset($validated['status'])) {
            $validated['is_active'] = $validated['status'] === 'active';
            unset($validated['status']);
        }

        return response()->json($this->userService->getPageData($validated));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(User::ROLES)],
            'contact_number' => ['required', 'string', 'regex:/^09\d{9}$/'],
            'password' => ['prohibited'],
            'password_confirmation' => ['prohibited'],
            'program' => ['nullable', 'string', Rule::exists('programs', 'code')],
            'program_ids' => ['nullable', 'array'],
            'program_ids.*' => ['integer', 'distinct', Rule::exists('programs', 'id')],
            'year_level' => ['nullable', 'string', 'max:20', 'required_if:role,secretary,program_secretary'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['program'])) {
            $validated['program'] = strtoupper(trim($validated['program']));
        }

        if (in_array($validated['role'], ['secretary', 'program_secretary'], true) && empty($validated['program'])) {
            return response()->json([
                'message' => 'A program is required for secretary accounts.',
                'errors' => ['program' => ['A program is required for secretary accounts.']],
            ], 422);
        }

        $created = $this->userService->create($validated);

        if (! $created['temporary_password_email_sent']) {
            return response()->json([
                'message' => 'The account could not be created because the temporary password email could not be sent. Please try again.',
            ], 503);
        }

        return response()->json([
            'message' => 'User account created successfully. A temporary password was emailed to the user.',
            'user' => $created['user'],
            'temporary_password_email_sent' => $created['temporary_password_email_sent'],
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', 'required', Rule::in(User::ROLES)],
            'contact_number' => ['sometimes', 'required', 'string', 'regex:/^09\d{9}$/'],
            'password' => ['sometimes', 'required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'program' => ['sometimes', 'nullable', 'string', Rule::exists('programs', 'code')],
            'program_ids' => ['sometimes', 'nullable', 'array'],
            'program_ids.*' => ['integer', 'distinct', Rule::exists('programs', 'id')],
            'year_level' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['program'])) {
            $validated['program'] = strtoupper(trim($validated['program']));
        }

        $role = $validated['role'] ?? $user->role;
        $program = array_key_exists('program', $validated)
            ? $validated['program']
            : $user->program;
        $yearLevel = array_key_exists('year_level', $validated)
            ? $validated['year_level']
            : $user->year_level;

        if (array_key_exists('program_ids', $validated)) {
            $program = ! empty($validated['program_ids']) && ! array_key_exists('program', $validated)
                ? Program::query()->whereKey($validated['program_ids'][0])->value('code')
                : $program;

            if ($validated['program_ids'] === []) {
                $program = null;
            }
        }

        $programIdsChanged = array_key_exists('program_ids', $validated)
            && $this->sortedIds($validated['program_ids'] ?? [])
                !== $this->sortedIds($user->programs()->pluck('programs.id')->all());
        $assignmentChanged = $role !== $user->role
            || strtoupper((string) $program) !== strtoupper((string) $user->program)
            || $programIdsChanged
            || (string) $yearLevel !== (string) $user->year_level;

        if (
            in_array($role, ['secretary', 'program_secretary'], true)
            && $assignmentChanged
            && (! $program || ! $yearLevel)
        ) {
            return response()->json([
                'message' => 'A program and year level are required for secretary accounts.',
                'errors' => [
                    'program' => ['A program is required for secretary accounts.'],
                    'year_level' => ['A year level is required for secretary accounts.'],
                ],
            ], 422);
        }

        $updatedUser = $this->userService->update($user, $validated, $request->user());

        return response()->json([
            'message' => 'User account updated successfully.',
            'user' => $updatedUser,
        ]);
    }

    private function sortedIds(array $ids): array
    {
        $ids = array_map('intval', $ids);
        sort($ids);

        return $ids;
    }
}
