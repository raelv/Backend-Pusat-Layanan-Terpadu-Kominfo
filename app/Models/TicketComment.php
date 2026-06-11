<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage; // <-- TAMBAHKAN BARIS INI

class TicketComment extends Model
{
    protected $fillable = [
        'ticket_id',
        'user_id',
        'message',
        'file_path',
    ];

    public function getFilePathAttribute($value)
    {
        return $value ? Storage::url($value) : null;
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}