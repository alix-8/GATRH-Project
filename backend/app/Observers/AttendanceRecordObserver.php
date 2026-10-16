<?php

namespace App\Observers;

use App\Models\AttendanceRecord;
use App\Services\Escalations\WeeklyEscalationService;

class AttendanceRecordObserver
{
    /**
     * Handle events after an AttendanceRecord is created or updated.
     */
    public function saved(AttendanceRecord $record): void
    {
        app(WeeklyEscalationService::class)->syncStudent($record->student_id);
    }

    /**
     * Handle events after an AttendanceRecord is deleted.
     */
    public function deleted(AttendanceRecord $record): void
    {
        app(WeeklyEscalationService::class)->syncStudent($record->student_id);
    }
}
