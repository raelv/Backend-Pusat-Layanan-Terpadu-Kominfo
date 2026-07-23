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
        'form_data', 'surat_permohonan_path', 'lampiran_tambahan_path', 
        'schedule_start', 'schedule_end', 
        'due_date', 'assigned_at', 'estimated_days', 'completed_at', 
        'status', 'is_skm_filled', 'rejection_reason',
        'zoom_link_id', 'disposed_at', 'overdue_notified_at', 'is_sla_notified'
    ];
    protected $casts = [
        'form_data' => 'array',
        'schedule_start' => 'datetime',
        'schedule_end' => 'datetime',
        'due_date' => 'datetime',
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
        'overdue_notified_at' => 'datetime', // ✅ TAMBAHKAN
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
    
    // Accessor URL Surat Permohonan
    public function getSuratPermohonanUrlAttribute()
    {
        return $this->surat_permohonan_path ? \Illuminate\Support\Facades\Storage::url($this->surat_permohonan_path) : null;
    }

    // Accessor URL Lampiran Tambahan
    public function getLampiranTambahanUrlAttribute()
    {
        return $this->lampiran_tambahan_path ? \Illuminate\Support\Facades\Storage::url($this->lampiran_tambahan_path) : null;
    }

    public function logs(): HasMany
    {
        return $this->hasMany(TicketLog::class)->orderBy('created_at', 'desc');
    }

        public function zoomLink(): BelongsTo
    {
        return $this->belongsTo(ZoomLink::class, 'zoom_link_id');
    }
}