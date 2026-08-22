<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'gathering_type',    // 'pm', 'ws', 'tg', 'special'
        'day',               // Date of gathering
        'batch',
        'venue',
        'validation_status', // 'unvalidated', 'approved', 'rejected'
        'submitted_by',
        'validated_by',
    ];

    protected $casts = [
        'day' => 'date',
    ];

    // The student this record belongs to
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

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
}