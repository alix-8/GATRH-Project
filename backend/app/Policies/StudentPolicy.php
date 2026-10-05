<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    /**
     * Determine if the user can manage/parse attendance for a given student.
     */
    public function manageStudent(User $user, Student $student): bool
    {
        // 1. Chancellors and CM Coordinators have global access
        if (in_array($user->role, ['chancellor', 'cm_coordinator'])) {
            return true;
        }

        // 2. Program Heads and Program Secretaries can access any student in their assigned program
        if (in_array($user->role, ['program_head', 'program_secretary'], true)) {
            return in_array($student->program, $user->assignedProgramCodes(), true);
        }

        // 3. Secretaries can ONLY access students in their program AND year level
        if ($user->role === 'secretary') {
            return $user->program === $student->program && 
                   (string)$user->year_level === (string)$student->year_level;
        }

        return false;
    }
}