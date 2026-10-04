<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Models\Student;
use App\Models\User;

class Escalation extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'no_response_count',
        'escalation_level',   // 'None', 'Level 1', 'Level 2', 'Level 3'
        'last_flagged_at',
    ];

    protected $casts = [
        'last_flagged_at' => 'datetime',
        'no_response_count' => 'integer',
    ];

    /** Limit escalation visibility to the levels assigned to each role. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $levels = match ($user->role) {
            'secretary' => ['Level 1', 'Level 2'],
            'program_head' => ['Level 2'],
            'chancellor', 'cm_coordinator' => ['Level 3'],
            default => [],
        };

        if ($levels === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereIn('escalation_level', $levels)
            ->where('no_response_count', '>', 0)
            ->whereHas('student', fn (Builder $students) => $students->accessibleTo($user));
    }

    // Belongs to a single student
    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
