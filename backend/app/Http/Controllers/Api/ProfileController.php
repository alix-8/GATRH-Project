<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function changeContact(Request $request)
    {
        $validated = $request->validate([
            'contact_number' => ['required', 'string', 'regex:/^09\d{9}$/'],
        ]);

        $user = $request->user();
        $user->update($validated);

        return response()->json([
            'message' => 'Contact number updated successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'program' => $user->program,
                'year_level' => $user->year_level,
                'contact_number' => $user->contact_number,
            ],
        ]);
    }

    /**
     * Helper to retrieve shared password validation rules across Auth actions.
     */
    private function passwordRules(): Password
    {
        return Password::min(8)
            ->mixedCase()
            ->numbers()
            ->symbols();
    }

    /**
     * Change the authenticated user's password after verifying the current one.
     */
    public function changePassword(Request $request)
    {
        try {
            $validated = $request->validate([
                'current_password' => ['required', 'string'],
                'password' => [
                    'required',
                    'confirmed',
                    'different:current_password',
                    $this->passwordRules(),
                ],
            ]);
        } catch (ValidationException $exception) {
            app(AuditLogService::class)->recordSecurityEvent('Changed password', $request->user(), 'failed', 'Password change input validation failed.');
            throw $exception;
        }

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            app(AuditLogService::class)->recordSecurityEvent('Changed password', $user, 'failed', 'Password change failed: incorrect current password.');
            return response()->json([
                'message' => 'The current password is incorrect.',
                'errors' => [
                    'current_password' => ['The current password is incorrect.'],
                ],
            ], 422);
        }

        $user->update([
            'password' => $validated['password'],
        ]);
        app(AuditLogService::class)->recordSecurityEvent('Changed password', $user);

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }
}
