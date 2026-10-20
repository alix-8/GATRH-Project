<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use App\Models\Student;

class AttendanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'student_id',
        'program',
        'year_level',
        'gathering_type',    // 'pm', 'ws', 'tg'
        'day',               // date of gathering
        'batch',
        'venue',
        'status',            // 'present', 'no_response'
        'validation_status', // 'unvalidated', 'validated'
        'submitted_by',
        'validated_by',
        'approved_at',
    ];

    protected $casts = [
        'day' => 'date',
        'approved_at' => 'datetime',
    ];

    // The Secretary who submitted this record
    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    // The Program Head or Coordinator who validated this record
    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}
