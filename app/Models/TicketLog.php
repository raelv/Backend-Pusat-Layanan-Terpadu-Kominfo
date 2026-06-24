<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketLog extends Model
{
    // Karena kita pakai created_at saja di migration, matikan updated_at
    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'action',
        'description',
        'properties',
        'created_at',
    ];

    protected $casts = [
        'properties' => 'array',
        'created_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Accessor untuk format nama actor (Manusia / Sistem)
     */
    public function getActorNameAttribute(): string
    {
        if ($this->user_id === null) {
            return 'Sistem';
        }

        $role = strtoupper($this->actor?->role ?? '');
        $name = $this->actor?->name ?? 'Unknown';

        return "{$name} ({$role})";
    }
}