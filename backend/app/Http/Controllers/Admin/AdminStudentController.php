<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Student;
use App\Services\AdminStudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminStudentController extends Controller
{
    public function __construct(private AdminStudentService $studentService)
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

        return response()->json($this->studentService->getPageData($validated));
    }

    public function show(Student $student): JsonResponse
    {
        return response()->json([
            'student' => $student->loadCount('attendanceRecords'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'program' => ['required', 'string', Rule::exists('programs', 'code')],
            'year_level' => ['required', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $student = $this->studentService->create($validated);

        return response()->json([
            'message' => 'Student created successfully.',
            'student' => $student,
        ], 201);
    }

    public function update(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'program' => ['sometimes', 'required', 'string', Rule::exists('programs', 'code')],
            'year_level' => ['sometimes', 'required', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $updatedStudent = $this->studentService->update($student, $validated);

        return response()->json([
            'message' => 'Student updated successfully.',
            'student' => $updatedStudent,
        ]);
    }
}
