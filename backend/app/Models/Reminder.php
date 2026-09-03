<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

/**
 * @property \Carbon\Carbon|null $due_date
 * @property \Carbon\Carbon|null $completed_at
 * @property \Carbon\Carbon|null $gathering_date
 */

class Reminder extends Model
{
    protected $fillable = [
        'user_id', 'reminder_type', 'parent_reminder_id',
        'gathering_type', 'program', 'year_level', 'gathering_date',
        'title', 'description', 'due_date', 'status', 'completed_at',
    ];

    protected $casts = [
        'due_date'       => 'datetime',
        'completed_at'   => 'datetime',
        'gathering_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parent()
    {
        return $this->belongsTo(Reminder::class, 'parent_reminder_id');
    }

    public function children()
    {
        return $this->hasMany(Reminder::class, 'parent_reminder_id');
    }

    /**
     * How many students have submitted attendance for this reminder's gathering.
     */
    public function respondedCount(): int
    {
        return AttendanceRecord::whereHas('student', function ($q) {
                $q->where('program', $this->program)
                  ->where('year_level', $this->year_level);
            })
            ->where('gathering_type', $this->gathering_type)
            ->where('day', $this->gathering_date->toDateString())
            ->count();
    }

    public function totalStudents(): int
    {
        return Student::where('program', $this->program)
            ->where('year_level', $this->year_level)
            ->count();
    }
}