<?php

namespace App\Services;

use App\Models\GatheringSchedule;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminSystemSettingService
{
    private const GATHERING_LABELS = [
        'pm' => 'Prayer Meeting',
        'ws' => 'Worship Service',
        'tg' => 'Thanksgiving',
    ];

    public function getGeneralSettings(): SystemSetting
    {
        return SystemSetting::query()->findOrFail(1);
    }

    public function updateGeneralSettings(array $data, User $actor): SystemSetting
    {
        return DB::transaction(function () use ($data, $actor) {
            $settings = SystemSetting::query()->lockForUpdate()->findOrFail(1);
            $settings->fill($data);
            $settings->updated_by = $actor->id;
            $settings->save();

            app(AuditLogService::class)->recordAction(
                'Updated system settings',
                'system_settings',
                (string) $settings->id,
                $actor
            );

            return $settings->fresh();
        });
    }

    public function getGatheringSchedules(): array
    {
        $schedules = GatheringSchedule::query()
            ->get()
            ->keyBy('gathering_type');

        return collect(self::GATHERING_LABELS)
            ->map(fn (string $label, string $type) => [
                'gathering_type' => $type,
                'label' => $label,
                'days' => $schedules->get($type)?->days ?? [],
            ])
            ->values()
            ->all();
    }

    public function updateGatheringSchedules(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor) {
            foreach ($data['schedules'] as $type => $days) {
                GatheringSchedule::query()->updateOrCreate(
                    ['gathering_type' => $type],
                    ['days' => array_values(array_unique($days))]
                );
            }

            app(AuditLogService::class)->recordAction(
                'Updated gathering schedules',
                'gathering_schedules',
                'all',
                $actor
            );

            return $this->getGatheringSchedules();
        });
    }
}
