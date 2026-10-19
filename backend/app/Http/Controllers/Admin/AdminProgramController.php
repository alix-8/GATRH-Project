<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\User;
use App\Services\Admin\AdminProgramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminProgramController extends Controller
{
    public function __construct(private AdminProgramService $programService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'program' => ['nullable', 'string', Rule::exists('programs', 'code')],
            'year_level' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if (isset($validated['status'])) {
            $validated['is_active'] = $validated['status'] === 'active';
            unset($validated['status']);
        }

        return response()->json($this->programService->getPageData($validated));
    }

    public function show(Program $program): JsonResponse
    {
        return response()->json([
            'program' => $this->programService->getProgram($program),
        ]);
    }

    public function headOptions(): JsonResponse
    {
        return response()->json([
            'program_head_options' => User::query()
                ->where('role', 'program_head')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:programs,code'],
            'name' => ['required', 'string', 'max:255'],
            'max_year_level' => ['sometimes', 'integer', Rule::in([2, 4])],
            'program_head_ids' => ['nullable', 'array'],
            'program_head_ids.*' => [
                'integer', 'distinct',
                Rule::exists('users', 'id')->where('role', 'program_head')->where('is_active', true),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $program = $this->programService->create($validated);

        return response()->json([
            'message' => 'Program created successfully.',
            'program' => $program,
        ], 201);
    }

    public function update(Request $request, Program $program): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'max_year_level' => ['sometimes', 'integer', Rule::in([2, 4])],
            'program_head_ids' => ['sometimes', 'nullable', 'array'],
            'program_head_ids.*' => [
                'integer', 'distinct',
                Rule::exists('users', 'id')->where('role', 'program_head')->where('is_active', true),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $updatedProgram = $this->programService->update($program, $validated);

        return response()->json([
            'message' => 'Program updated successfully.',
            'program' => $updatedProgram,
        ]);
    }
}
