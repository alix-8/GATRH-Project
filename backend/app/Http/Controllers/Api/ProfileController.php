<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Hash;

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
            'user' => $user->fresh(),
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
    function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', $this->passwordRules()],
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
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

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }
}