<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;


class Reminder extends Model
{

    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'description', 
        'due_date',
        'status',       //'upcoming', 'needs_actipn', 'completed'
    ];

    protected $casts = [
        'due_date' => 'date', 
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }


}
