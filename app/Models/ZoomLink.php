<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZoomLink extends Model
{
    protected $fillable = ['title', 'link', 'status', 'used_by_ticket_id'];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'used_by_ticket_id');
    }

    // Scope untuk ambil link yang sedang kosong
    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }
}