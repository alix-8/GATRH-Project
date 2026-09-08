<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

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

}