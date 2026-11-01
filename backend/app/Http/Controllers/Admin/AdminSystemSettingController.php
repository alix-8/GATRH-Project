<?php

namespace App\Http\Controllers\Admin;

use DateTimeZone;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Admin\AdminSystemSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminSystemSettingController extends Controller
{
    public function __construct(private AdminSystemSettingService $settingService)
    {
    }

    public function show(): JsonResponse
    {
        $timezoneOptions = collect(DateTimeZone::listIdentifiers())
            ->map(function (string $timezone) {
                $offset = now()->setTimezone($timezone)->format('P');

                return [
                    'value' => $timezone,
                    'label' => "{$timezone} (UTC{$offset})",
                    'utc_offset' => $offset,
                ];
            })
            ->values();

        return response()->json([
            'settings' => $this->settingService->getGeneralSettings(),
            'timezone_options' => $timezoneOptions,
        ]);
    }

    public function branding(): JsonResponse
    {
        return response()->json([
            'system_name' => SystemSetting::whereKey(1)->value('system_name') ?: SystemSetting::DEFAULT_SYSTEM_NAME,
             'admin_email' => User::query()
                ->where('role', 'admin')
                ->where('is_active', true)
                ->orderBy('id')
                ->value('email'),
        ])->header('Cache-Control', 'no-store');

        
    }

    public function update(Request $request): JsonResponse
    {
        if ($request->has('academic_year')
            && $request->input('academic_year') !== $this->settingService->getGeneralSettings()->academic_year) {
            throw ValidationException::withMessages([
                'academic_year' => ['Use Academic Year Rollover to advance the current school year.'],
            ]);
        }
        $request->request->remove('academic_year');

        if (is_string($request->input('system_name'))) {
            $request->merge(['system_name' => trim(preg_replace('/\s+/u', ' ', $request->input('system_name')))]);
        }

        $validated = $request->validate([
            'system_name' => ['sometimes', 'required', 'string', 'max:120'],
            'academic_year' => ['sometimes', 'required', 'string', 'regex:/^\d{4}-\d{4}$/'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone'],
        ]);

        $settings = $this->settingService->updateGeneralSettings(
            $validated,
            $request->user()
        );

        return response()->json([
            'message' => 'System settings updated successfully.',
            'settings' => $settings,
        ]);
    }

    public function showGatheringSchedules(): JsonResponse
    {
        return response()->json([
            'schedules' => $this->settingService->getGatheringSchedules(),
            'weekdays' => [
                'monday', 'tuesday', 'wednesday', 'thursday',
                'friday', 'saturday', 'sunday',
            ],
        ]);
    }

    public function updateGatheringSchedules(Request $request): JsonResponse
    {
        $weekdays = [
            'monday', 'tuesday', 'wednesday', 'thursday',
            'friday', 'saturday', 'sunday',
        ];

        $validated = $request->validate([
            'schedules' => ['required', 'array:pm,ws,tg', 'min:1'],
            'schedules.pm' => ['sometimes', 'required', 'array', 'min:1'],
            'schedules.pm.*' => ['required', 'string', 'distinct', Rule::in($weekdays)],
            'schedules.ws' => ['sometimes', 'required', 'array', 'min:1'],
            'schedules.ws.*' => ['required', 'string', 'distinct', Rule::in($weekdays)],
            'schedules.tg' => ['sometimes', 'required', 'array', 'min:1'],
            'schedules.tg.*' => ['required', 'string', 'distinct', Rule::in($weekdays)],
        ]);

        return response()->json([
            'message' => 'Gathering schedules updated successfully.',
            'schedules' => $this->settingService->updateGatheringSchedules(
                $validated,
                $request->user()
            ),
        ]);
    }
}
