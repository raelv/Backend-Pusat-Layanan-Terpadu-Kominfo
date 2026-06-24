<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketReminderLog extends Model
{
    protected $fillable = [
        'ticket_id', 
        'staff_id', 
        'reminder_level', 
        'message', 
        'sent_at'
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    // Relasi ke tiket
    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    // Relasi ke staff
    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}