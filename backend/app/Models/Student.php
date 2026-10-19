<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Escalation;
use App\Models\User;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'program',
        'year_level',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAccessibleTo($query, User $user)
    {
        if (in_array($user->role, ['chancellor', 'cm_coordinator'])) {
            return $query;
        }

        if (in_array($user->role, ['program_head', 'program_secretary'], true)) {
            return $query->whereIn('program', $user->assignedProgramCodes());
        }

        if ($user->role === 'secretary') {
            return $query->where('program', $user->program)
                ->where('year_level', $user->year_level);
        }

        return $query->whereRaw('1 = 0');
    }

    // A student has many attendance records over time
    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    // Each student has exactly one escalation tracking row
    public function escalation()
    {
        return $this->hasOne(Escalation::class);
    }
}
