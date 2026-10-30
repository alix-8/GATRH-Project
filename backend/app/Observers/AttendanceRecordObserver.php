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
        if ($record->wasChanged('validation_status') && $record->getRawOriginal('validation_status') === 'validated'
            && $record->validation_status !== 'validated') {
            $this->revokeFinalization($record);
        }
        app(WeeklyEscalationService::class)->syncStudent($record->student_id);
    }

    /**
     * Handle events after an AttendanceRecord is deleted.
     */
    public function deleted(AttendanceRecord $record): void
    {
        $this->revokeFinalization($record);
        app(WeeklyEscalationService::class)->syncStudent($record->student_id);
    }

    private function revokeFinalization(AttendanceRecord $record): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('monthly_export_tasks')) return;
        app(\App\Services\Reminders\MonthlyExportService::class)->revokeFinalization(
            $record->program ?? $record->student?->program,
            $record->year_level ?? $record->student?->year_level,
            $record->reporting_day ?? $record->day
        );
    }
}
