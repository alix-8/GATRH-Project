<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRules;

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

        if (! $user->is_active) {
            return response()->json(['message' => 'This account is inactive.'], 403);
        }

        // Retire the previous browser bearer-token sessions during migration
        // to the HttpOnly first-party session cookie.
        $user->tokens()->delete();
        $remember = $request->boolean('remember');
        Auth::login($user, $remember);
        $request->session()->regenerate();

        return response()->json([
            // Keep the login response limited to the fields needed to route
            // the user after sign-in. The session cookie is HttpOnly.
            'user'         => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
            ],
            'message'      => 'Login successful',
        ]);
    }

    // Get the authenticated user's information
    function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user'    => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'program' => $user->program,
                'year_level' => $user->year_level,
                'contact_number' => $user->contact_number,
            ],
            'message' => 'User information retrieved successfully',
        ]);
    }

    function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out successfully']);
    }

    /** Send a short-lived password reset code to the user's email. */
    function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim($request->input('email')));
        $sendKey = 'password-reset-send:' . $email . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($sendKey, 3)) {
            return response()->json(['message' => 'Too many code requests. Please wait a few minutes and try again.'], 429);
        }
        RateLimiter::hit($sendKey, 600);

        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json([
                'message' => 'We couldn’t find an account with that email address. Check it or try another email.',
            ], 404);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $table = config('auth.passwords.users.table');
        DB::table($table)->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($code), 'created_at' => now()]
        );

        try {
            Mail::send([
                'html' => 'emails.password-reset-code',
                'text' => 'emails.password-reset-code-text',
            ], [
                'code' => $code,
                'expiresIn' => config('auth.passwords.users.expire'),
            ], function ($message) use ($user): void {
                $message->to($user->email)->subject('Your GATRH password reset code');
            });
        } catch (\Throwable $exception) {
            DB::table($table)->where('email', $user->email)->delete();
            report($exception);
            return response()->json(['message' => 'We couldn’t send the reset code right now. Please try again later.'], 503);
        }

        RateLimiter::clear($this->passwordResetAttemptKey($user->email, $request));

        return response()->json([
            'message' => 'A password reset code has been sent to your email.',
        ]);
    }

    /** Verify the emailed code before showing the new-password form. */
    function verifyPasswordResetCode(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6',
        ]);

        $key = $this->passwordResetAttemptKey($validated['email'], $request);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many incorrect code attempts. Request a new code and try again.'], 429);
        }

        if (!$this->passwordResetCodeIsValid($validated['email'], $validated['code'])) {
            RateLimiter::hit($key, 600);
            return response()->json(['message' => 'That code is incorrect or expired. Check it and try again.'], 422);
        }

        RateLimiter::clear($key);
        return response()->json(['message' => 'Code verified.']);
    }

    /** Reset a user's password using the emailed code. */
    function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6',
            'password' => [
                'required',
                'string',
                'confirmed',
                PasswordRules::min(8)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $key = $this->passwordResetAttemptKey($validated['email'], $request);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many incorrect code attempts. Request a new code and try again.'], 429);
        }

        if (!$this->passwordResetCodeIsValid($validated['email'], $validated['code'])) {
            RateLimiter::hit($key, 600);
            return response()->json(['message' => 'That code is incorrect or expired. Request a new code and try again.'], 422);
        }

        $user = User::where('email', strtolower(trim($validated['email'])))->firstOrFail();
        $user->password = $validated['password'];
        $user->setRememberToken(Str::random(60));
        $user->save();
        $user->tokens()->delete();

        DB::table(config('auth.passwords.users.table'))
            ->where('email', $user->email)
            ->delete();
        RateLimiter::clear($key);

        return response()->json(['message' => 'Password reset successfully.']);
    }

    private function passwordResetAttemptKey(string $email, Request $request): string
    {
        return 'password-reset-code:' . strtolower(trim($email)) . '|' . $request->ip();
    }

    private function passwordResetCodeIsValid(string $email, string $code): bool
    {
        $table = config('auth.passwords.users.table');
        $record = DB::table($table)->where('email', strtolower(trim($email)))->first();
        if (!$record) {
            return false;
        }

        $expiresAt = \Illuminate\Support\Carbon::parse($record->created_at)
            ->addMinutes(config('auth.passwords.users.expire'));
        if ($expiresAt->isPast()) {
            DB::table($table)->where('email', $record->email)->delete();
            return false;
        }

        return Hash::check($code, $record->token);
    }
}
