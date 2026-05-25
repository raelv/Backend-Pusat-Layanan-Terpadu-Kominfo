<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'service_id', 'assigned_staff_id', 
        'form_data', 'schedule_start', 'schedule_end', 
        'status', 'is_skm_filled'
    ];

    protected $casts = [
        'form_data' => 'array',
        'schedule_start' => 'datetime',
        'schedule_end' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }
}