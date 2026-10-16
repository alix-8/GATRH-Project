<?php

namespace App\Observers;

use App\Models\AttendanceDraft;
use App\Services\Escalations\WeeklyEscalationService;

class AttendanceDraftObserver
{
    public function saved(AttendanceDraft $draft): void
    {
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
