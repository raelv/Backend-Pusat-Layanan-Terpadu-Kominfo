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
        'zoom_link_id', 'disposed_at', 'overdue_notified_at', 'is_sla_notified',
        'resubmitted_at'
    ];
    protected $casts = [
        'form_data' => 'array',
        'schedule_start' => 'datetime',
        'schedule_end' => 'datetime',
        'due_date' => 'datetime',
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
        'overdue_notified_at' => 'datetime',
        'resubmitted_at' => 'datetime',
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

        // Tambahkan properti ini di atas
    protected $appends = ['remaining_days'];

    // Tambahkan method ini
    public function getRemainingDaysAttribute()
    {
        if (in_array($this->status, ['completed', 'rejected', 'cancelled', 'expired']) || is_null($this->due_date)) {
            return null;
        }

        $now = \Carbon\Carbon::now('Asia/Makassar')->startOfDay();
        $dueDate = $this->due_date->copy()->startOfDay();

        if ($this->service && strtolower($this->service->category) === 'it') {
            $diff = $now->diffInDays($dueDate, false);
            return $diff === 0 ? 0 : $diff;
        }

        $startDate = $now->lt($dueDate) ? $now : $dueDate;
        $endDate = $now->lt($dueDate) ? $dueDate : $now;

        $totalDays = $startDate->diffInDays($endDate);

        $weeks = floor($totalDays / 7);
        $remainingDays = $totalDays % 7;

        $weekendCount = ($weeks * 2);
        $currentDay = $startDate->dayOfWeek;
        for ($i = 0; $i < $remainingDays; $i++) {
            if (in_array(($currentDay + $i) % 7, [0, 6])) {
                $weekendCount++;
            }
        }

        $holidayDates = \App\Models\Holiday::pluck('date')->map(fn ($d) => $d->format('m-d'))->toArray();

        $holidayCount = 0;
        $period = \Carbon\CarbonPeriod::create($startDate, $endDate);
        foreach ($period as $day) {
            if (!$day->isWeekend() && in_array($day->format('m-d'), $holidayDates)) {
                $holidayCount++;
            }
        }

        $workingDays = $totalDays - $weekendCount - $holidayCount;

        return $now->gt($dueDate) ? (-$workingDays) : $workingDays;
    }

    public function getReportTitleAttribute()
    {
        $candidates = [
            'nama_aplikasi', 'namaAplikasi',
            'topik',
            'nama_acara', 'namaAcara',
            'nama_kegiatan', 'namaKegiatan',
            'tema',
            'acara',
            'agenda',
            'nama_rapat', 'namaRapat',
            'judul_rapat', 'judulRapat',
            'judul_acara', 'judulAcara',
            'judul',
            'perihal',
            'keperluan',
            'materi',
            'topikMeeting', 'topik_meeting',
        ];

        $form = $this->form_data ?? [];

        foreach ($candidates as $key) {
            if (isset($form[$key]) && $form[$key] !== '' && $form[$key] !== null && $form[$key] !== []) {
                $value = $form[$key];
                return is_array($value) ? implode(', ', array_map('strval', $value)) : trim((string) $value);
            }
        }

        return $this->service->name ?? 'Tanpa Judul';
    }

    public function getReportStaffNameAttribute()
    {
        if ($this->staff) {
            return $this->staff->name;
        }

        $lastStaffLog = TicketLog::where('ticket_id', $this->id)
            ->whereIn('action', ['CLAIMED', 'IN_PROGRESS', 'COMPLETED'])
            ->whereNotNull('user_id')
            ->orderByDesc('id')
            ->first();

        if ($lastStaffLog && $lastStaffLog->actor) {
            return $lastStaffLog->actor->name;
        }

        return 'Belum Ditugaskan';
    }
}