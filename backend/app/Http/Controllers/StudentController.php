<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Program Heads and Secretaries only see students from their program
        if ($user->isProgramHead() || $user->isSecretary()) {
            return response()->json(Student::where('program', $user->program)->get());
        }

        // Coordinators & Chancellors see all students
        return response()->json(Student::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'program' => 'required|string',
            'year_level' => 'required|string',
        ]);

        $student = Student::create($validated);

        // Auto-initialize escalation record
        $student->escalation()->create([
            'no_response_count' => 0,
            'escalation_level' => 'None',
        ]);

        return response()->json($student, 201);
    }
}