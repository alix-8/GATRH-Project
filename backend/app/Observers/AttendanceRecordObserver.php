<?php

namespace App\Observers;

use App\Models\AttendanceRecord;
use App\Models\Escalation;

class AttendanceRecordObserver
{
    /**
     * Handle events after an AttendanceRecord is created or updated.
     */
    public function saved(AttendanceRecord $record): void
    {
        $this->syncStudentEscalation($record->student_id);
    }

    /**
     * Handle events after an AttendanceRecord is deleted.
     */
    public function deleted(AttendanceRecord $record): void
    {
        $this->syncStudentEscalation($record->student_id);
    }

    /**
     * Core Auto-Calculation Logic
     */
    private function syncStudentEscalation(int $studentId): void
    {
        // Automatically count all 'no_response' entries for this student
        $noResponseCount = AttendanceRecord::where('student_id', $studentId)
            ->where('status', 'no_response')
            ->count();

        // Automatically assign escalation level based on thresholds
        $level = match (true) {
            $noResponseCount >= 3 => 'Level 3',
            $noResponseCount >= 2 => 'Level 2',
            $noResponseCount >= 1 => 'Level 1',
            default               => 'None',
        };

        // Auto-update or create the Escalation record
        Escalation::updateOrCreate(
            ['student_id' => $studentId],
            [
                'no_response_count' => $noResponseCount,
                'escalation_level'  => $level,
            ]
        );
    }
}