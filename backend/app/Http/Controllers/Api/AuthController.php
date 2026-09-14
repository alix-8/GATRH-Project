<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{

    function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'remember' => 'sometimes|boolean',
        ]);

        $throttleKey = 'login:' . strtolower(trim($request->input('email'))) . '|' . $request->ip();
        $maxAttempts = config('auth.login_throttle.max_attempts');
        $decaySeconds = config('auth.login_throttle.decay_seconds');

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            return response()->json([
                'message' => 'Too many failed login attempts. Please try again later.',
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ], 429);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            RateLimiter::hit($throttleKey, $decaySeconds);

            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        RateLimiter::clear($throttleKey);

        $user->load('programs');

        $remember = $request->boolean('remember');
        $expiresAt = $remember
            ? null
            : now()->addMinutes(config('auth.token.session_minutes'));
        $token = $user->createToken('auth_token', ['*'], $expiresAt)->plainTextToken;

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