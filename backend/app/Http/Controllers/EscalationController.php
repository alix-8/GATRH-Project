<?php

namespace App\Http\Controllers;

use App\Models\Escalation;
use Illuminate\Http\Request;

class EscalationController extends Controller
{
    public function index(Request $request)
    {
        $escalations = Escalation::with('student')
            ->where('no_response_count', '>', 0)
            ->orderBy('no_response_count', 'desc')
            ->get();

        return response()->json($escalations);
    }
}