<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\AdminSystemSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminSystemSettingController extends Controller
{
    public function __construct(private AdminSystemSettingService $settingService)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => $this->settingService->getGeneralSettings(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'institution_name' => ['sometimes', 'required', 'string', 'max:255'],
            'academic_year' => ['sometimes', 'required', 'string', 'regex:/^\d{4}-\d{4}$/'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone'],
            'date_format' => ['sometimes', 'required', Rule::in([
                'MM/DD/YYYY',
                'DD/MM/YYYY',
                'YYYY-MM-DD',
            ])],
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
