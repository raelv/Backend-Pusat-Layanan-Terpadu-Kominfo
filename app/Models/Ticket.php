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

        // Tambahkan properti ini di atas
    protected $appends = ['remaining_days'];

    // Tambahkan method ini
    public function getRemainingDaysAttribute()
    {
        // 1. Return null jika sudah selesai, ditolak, atau tidak ada due_date
        if (in_array($this->status, ['completed', 'rejected', 'cancelled']) || is_null($this->due_date)) {
            return null;
        }

        $now = \Carbon\Carbon::now('Asia/Makassar')->startOfDay();
        $dueDate = $this->due_date->startOfDay();

        // ✅ PENGECEKAN EXCEPTION: Khusus Layanan IT, pakai selisih hari kalender biasa
        if ($this->service && strtolower($this->service->category) === 'it') {
            $diff = $now->diffInDays($dueDate, false); // false = mengembalikan angka negatif jika sudah lewat
            return $diff === 0 ? 0 : $diff; 
        }

        // --- LOGIKA LAMA UNTUK ZOOM & COMMAND CENTER (Menghitung Hari Kerja) ---
        
        // Daftar hari libur nasional (Format m-d)
        $holidays = [
            '01-01', '25-03', '29-03', '01-05', '10-05', 
            '01-06', '07-06', '17-08', '16-09', '20-12', '25-12', '26-12'
        ];

        // Pastikan start date lebih kecil dari end date untuk perhitungan
        $startDate = $now->lt($dueDate) ? $now : $dueDate;
        $endDate = $now->lt($dueDate) ? $dueDate : $now;

        // 2. Hitung total hari kalender
        $totalDays = $startDate->diffInDays($endDate);

        // 3. Hitung total weekend (Sabtu & Minggu) secara matematis
        $weeks = floor($totalDays / 7);
        $remainingDays = $totalDays % 7;
        
        $weekendCount = ($weeks * 2); 
        $currentDay = $startDate->dayOfWeek; 
        for ($i = 0; $i < $remainingDays; $i++) {
            if (in_array(($currentDay + $i) % 7, [0, 6])) { 
                $weekendCount++;
            }
        }

        // 4. Hitung total hari libur nasional yang jatuh di hari kerja
        $holidayCount = 0;
        $period = \Carbon\CarbonPeriod::create($startDate, $endDate);
        foreach ($period as $day) {
            if (!$day->isWeekend() && in_array($day->format('m-d'), $holidays)) {
                $holidayCount++;
            }
        }

        // 5. Hitung sisa hari kerja aktual
        $workingDays = $totalDays - $weekendCount - $holidayCount;

        // 6. Return negatif jika sudah lewat, positif jika masih tersisa
        return $now->gt($dueDate) ? (-$workingDays) : $workingDays;
    }
}