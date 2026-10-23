<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Student;
use App\Services\Admin\AdminStudentService;
use App\Services\Admin\StudentImportFileReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminStudentController extends Controller
{
    public function __construct(private AdminStudentService $studentService, private StudentImportFileReader $importFileReader)
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
    
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'extensions:csv,xlsx', 'max:2048'],
            'program' => ['nullable', 'required_with:year_level', 'string', Rule::exists('programs', 'code')],
            'year_level' => ['nullable', 'required_with:program', Rule::in(['1', '2', '3', '4'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $lines = $this->importFileReader->read($request->file('file'));
        $headers = array_shift($lines);
        if (! is_array($headers)) {
            throw ValidationException::withMessages(['file' => ['The file is empty.']]);
        }

        $headers = array_map(fn ($header) => strtolower(trim((string) $header, " \t\n\r\0\x0B\xEF\xBB\xBF")), $headers);
        $columns = array_flip($headers);
        $nameColumn = $columns['full_name'] ?? $columns['full name'] ?? $columns['name'] ?? null;
        if ($nameColumn === null || (! isset($validated['program']) && ! isset($columns['program']))
            || (! isset($validated['year_level']) && ! isset($columns['year_level']))) {
            throw ValidationException::withMessages(['file' => ['Include a Full Name column. If Program and Year Level are not selected, the file must also have program and year_level columns.']]);
        }

        $validPrograms = Program::query()->pluck('code')->all();
        $students = [];
        foreach ($lines as $index => $values) {
            if (count($values) === 1 && trim((string) $values[0]) === '') {
                continue;
            }

            $name = trim((string) ($values[$nameColumn] ?? ''));
            $program = strtoupper(trim((string) ($validated['program'] ?? $values[$columns['program']] ?? '')));
            $yearLevel = trim((string) ($validated['year_level'] ?? $values[$columns['year_level']] ?? ''));
            $status = strtolower(trim((string) ($values[$columns['status'] ?? -1] ?? 'active')));
            if ($name === '' || mb_strlen($name) > 255 || ! in_array($program, $validPrograms, true)
                || ! in_array($yearLevel, $program === 'ACT' ? ['1', '2'] : ['1', '2', '3', '4'], true)
                || (! isset($validated['is_active']) && ! in_array($status, ['active', 'inactive'], true))) {
                throw ValidationException::withMessages(['file' => ['Invalid student data on row ' . ($index + 2) . '. Check the name, program, year level, and status.']]);
            }
            $students[] = [
                'name' => $name,
                'program' => $program,
                'year_level' => $yearLevel,
                'is_active' => isset($validated['is_active']) ? (bool) $validated['is_active'] : $status === 'active',
            ];
            if (count($students) > 5000) {
                throw ValidationException::withMessages(['file' => ['Import up to 5,000 students at a time.']]);
            }
        }

        if ($students === []) {
            throw ValidationException::withMessages(['file' => ['The file has no student rows.']]);
        }

        DB::transaction(function () use ($students): void {
            foreach ($students as $student) {
                $this->studentService->create($student);
            }
        });

        return response()->json(['message' => count($students) . ' students imported successfully.', 'count' => count($students)], 201);
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

    public function destroy(Student $student): JsonResponse
    {
        $this->studentService->deactivate($student);

        return response()->json([
            'message' => 'Student marked as inactive successfully.',
        ]);
    }

    public function rolloverSchoolYear(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'school_year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'preview_token' => ['required', 'string', 'size:64'],
        ]);

        [$startYear, $endYear] = array_map('intval', explode('-', $validated['school_year']));
        if ($endYear !== $startYear + 1) {
            return response()->json([
                'message' => 'The school year must use consecutive years, such as 2026-2027.',
                'errors' => ['school_year' => ['The school year must use consecutive years.']],
            ], 422);
        }

        $result = $this->studentService->rolloverSchoolYear(
            $validated['school_year'],
            $request->user(),
            $validated['preview_token'],
        );

        return response()->json($result);
    }

    public function previewSchoolYearRollover(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'school_year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
        ]);

        return response()->json($this->studentService->previewSchoolYearRollover($validated['school_year']));
    }

    public function schoolYearRolloverStatus(): JsonResponse
    {
        return response()->json($this->studentService->schoolYearRolloverStatus());
    }

    public function undoSchoolYearRollover(Request $request, int $rollover): JsonResponse
    {
        return response()->json($this->studentService->undoSchoolYearRollover($rollover, $request->user()));
    }
}
