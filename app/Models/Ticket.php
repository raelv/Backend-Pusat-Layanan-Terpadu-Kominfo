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
        'ticket_number',
        'user_id', 'service_id', 'assigned_staff_id', 
        'form_data', 'schedule_start', 'schedule_end', 
        'due_date', 'assigned_at', 'estimated_days', 'completed_at', 
        'status', 'is_skm_filled'
    ];

    protected $casts = [
        'form_data' => 'array',
        'schedule_start' => 'datetime',
        'schedule_end' => 'datetime',
        'due_date' => 'datetime',
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
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

    // Cek apakah tiket ini terlambat
    public function getIsOverdueAttribute()
    {
        if (!$this->due_date || in_array($this->status, ['completed', 'rejected', 'cancelled', 'expired'])) {
            return false;
        }
        return now()->greaterThan($this->due_date);
    }

    // Teks estimasi untuk Frontend
        // Teks estimasi untuk Frontend
    public function getSlaTextAttribute()
    {
        // Jika tiket sudah selesai/dibatalkan/expired, tidak perlu tampilkan hitungan mundur
        if (in_array($this->status, ['completed', 'rejected', 'cancelled', 'expired'])) {
            return null; 
        }

        // Jika ini layanan IT, dan belum di-claim oleh staff (due_date masih kosong)
        if (!$this->due_date && is_null($this->assigned_at)) {
            return 'Menunggu Estimasi Staff';
        }

        // Jika melewati target waktu
        if ($this->is_overdue) {
            return 'Terlambat';
        }

        // Jika sedang dalam pengerjaan dan belum terlambat
        if ($this->due_date) {
            $sisaHari = now()->diffInDays($this->due_date);
            return $sisaHari . ' Hari Kerja Lagi';
        }

        return null;
    }
}