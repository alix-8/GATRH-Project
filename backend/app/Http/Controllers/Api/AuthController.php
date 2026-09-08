<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
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

    function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user->load('programs');

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user,
            'message'      => 'Login successful',
        ]);
    }

    // Get the authenticated user's information
    function me(Request $request)
    {
        $request->user()->load('programs');

        return response()->json([
            'user'    => $request->user(),
            'message' => 'User information retrieved successfully',
        ]);
    }

    function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
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

    /**
     * Send a password reset link to the user's email.
     */
    function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $status = PasswordBroker::sendResetLink(
            $request->only('email')
        );

        return $status === PasswordBroker::RESET_LINK_SENT
            ? response()->json([
                'message' => 'Password reset link sent to your email.'
            ], 200)
            : response()->json([
                'message' => __($status)
            ], 400);
    }
}