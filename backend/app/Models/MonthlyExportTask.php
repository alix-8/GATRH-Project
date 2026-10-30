<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyExportTask extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'month' => 'date', 'exported_at' => 'datetime', 'download_expires_at' => 'datetime',
        'exported_approved' => 'boolean', 'download_approved' => 'boolean',
    ];

    public function reminder()
    {
        return $this->belongsTo(Reminder::class);
    }
}
