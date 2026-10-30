<?php

namespace App\Observers;

use App\Models\AttendanceDraft;
use App\Services\Escalations\WeeklyEscalationService;

class AttendanceDraftObserver
{
    public function saved(AttendanceDraft $draft): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('monthly_export_tasks')) {
            $entry = collect($draft->parsed_data ?? [])->first() ?? [];
            app(\App\Services\Reminders\MonthlyExportService::class)->revokeFinalization(
                $entry['program'] ?? $draft->user?->program,
                $entry['year_level'] ?? $draft->user?->year_level, $draft->day
            );
        }
        $previousData = $draft->getRawOriginal('parsed_data');
        if (is_string($previousData)) {
            $previousData = json_decode($previousData, true) ?: [];
        }

        app(WeeklyEscalationService::class)->syncDraft(
            $draft,
            is_array($previousData) ? $previousData : []
        );
    }

    public function deleted(AttendanceDraft $draft): void
    {
        app(WeeklyEscalationService::class)->syncDraft($draft);
    }
}
