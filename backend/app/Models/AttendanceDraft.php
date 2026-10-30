<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceDraft extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'gathering_type',
        'day',
        'raw_text',
        'parsed_data',
        'total_detected',
        'matched_count',
        'status',
        'unsubmitted_at',
    ];

    protected $casts = [
        'day'           => 'date',
        'unsubmitted_at' => 'datetime',
        'parsed_data'   => 'array',
        'unparsed_data' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeInReportingRange($query, array $range)
    {
        $dates = array_map(fn ($date) => \Carbon\Carbon::parse($date)->toDateString(), $range);
        return $query->whereRaw('DATE(day) BETWEEN ? AND ?', $dates);
    }
}
