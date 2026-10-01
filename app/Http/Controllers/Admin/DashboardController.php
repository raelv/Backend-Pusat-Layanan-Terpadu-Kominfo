<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        try {
            $totalTickets = Ticket::count();
            $completedTickets = Ticket::where('status', 'completed')->count();
            $pendingTickets = Ticket::whereIn('status', ['pending_approval', 'pending', 'queued'])->count();
            $inProgressTickets = Ticket::whereIn('status', ['assigned', 'in_progress'])->count();

            $rekapStatus = [
                'pending'     => Ticket::whereIn('status', ['pending', 'queued', 'pending_approval'])->count(),
                'in_progress' => Ticket::whereIn('status', ['assigned', 'in_progress'])->count(),
                'completed'   => $completedTickets,
                'rejected'    => Ticket::where('status', 'rejected')->count(),
                'expired'     => Ticket::where('status', 'expired')->count(),
            ];

            $rekapTugas = Ticket::select('services.category', DB::raw('count(*) as total'))
                ->join('services', 'tickets.service_id', '=', 'services.id')
                ->whereIn('services.category', ['it', 'zoom', 'command_center'])
                ->groupBy('services.category')
                ->pluck('total', 'category')->toArray();

            $staffData = User::where('role', 'staff')
                ->with('bidangs')
                ->withCount(['assignedTasks as active_tasks' => function ($query) {
                    $query->whereIn('status', ['assigned', 'in_progress', 'approved_admin']);
                }])
                ->get()
                ->map(function ($staff) {
                    return [
                        'id' => $staff->id,
                        'name' => $staff->name,
                        'nip' => $staff->nip,
                        'bidang' => $staff->bidang ?? '-',
                        'bidangs' => $staff->bidangs ?? [],
                        'attendance_status' => $staff->attendance_status,
                        'active_task_count' => $staff->active_tasks,
                        'is_overloaded' => $staff->active_tasks >= 2,
                        'service_access' => $staff->service_access ?? []
                    ];
                });

            return response()->json([
                'stats' => [
                    'total' => $totalTickets,
                    'completed' => $completedTickets,
                    'pending' => $pendingTickets,
                    'in_progress' => $inProgressTickets,
                ],
                'rekap_status' => $rekapStatus,
                'rekap_tugas' => [
                    'it' => $rekapTugas['it'] ?? 0,
                    'zoom' => $rekapTugas['zoom'] ?? 0,
                    'command_center' => $rekapTugas['command_center'] ?? 0,
                ],
                'staff' => $staffData
            ]);

            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Gagal ambil data dashboard', [
                    'error' => $e->getMessage(),
                ]);

                return response()->json(['message' => 'Gagal mengambil data dashboard. Silakan coba lagi.'], 500);
            }
    }

    public function monitorStaff()
    {
        return $this->index();
    }

    public function getPendingDispositions()
    {
        $now = \Carbon\Carbon::now('Asia/Makassar');

        $tickets = Ticket::with(['service', 'requester'])
            ->whereNull('assigned_staff_id') 
            ->whereNotIn('status', ['rejected', 'cancelled', 'completed']) 
            ->where(function($query) use ($now) {
                $query->whereNull('schedule_end')
                      ->orWhere('schedule_end', '>', $now);
            })
            ->orderBy('created_at', 'asc')
            ->get();

        // ... kode bawahnya biarkan apa adanya ...

        // ✅ LOGIC OVERDUE UNTUK TABEL AKTIF
        $formatted = $tickets->map(function ($ticket) use ($now) {
            $isOverdueSchedule = false;
            $overdueMinutes = null;
            $overdueText = null;

            if (in_array(strtolower($ticket->service->category ?? ''), ['zoom', 'command_center']) && $ticket->schedule_start) {
                if ($now->gt($ticket->schedule_start)) {
                    $isOverdueSchedule = true;
                    $overdueMinutes = abs(round($now->diffInMinutes($ticket->schedule_start)));
                    $overdueText = "Lewat Jadwal (Terlambat {$overdueMinutes} menit)";
                }
            }

            return [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'service' => $ticket->service,
                'requester' => $ticket->requester,
                'form_data' => $ticket->form_data,
                'schedule_start' => $ticket->schedule_start,
                'schedule_end' => $ticket->schedule_end,
                'status' => $ticket->status,
                'created_at' => $ticket->created_at,
                'is_overdue_schedule' => $isOverdueSchedule,
                'overdue_minutes' => $overdueMinutes,
                'overdue_text' => $overdueText,
            ];
        });

        return response()->json($formatted);
    }

        public function getExpiredDispositions()
    {
        $now = \Carbon\Carbon::now('Asia/Makassar');

        // ✅ AMBIL TIKET YANG SUDAH MELEWATI JAM SELESAI DAN BELUM DI-DISPOSE
        $tickets = Ticket::with(['service', 'requester'])
            ->whereNull('assigned_staff_id')
            ->whereNotIn('status', ['rejected', 'cancelled', 'completed'])
            ->whereNotNull('schedule_end')
            ->where('schedule_end', '<=', $now)
            ->orderBy('created_at', 'desc')
            ->get();

        // Tidak perlu flag overdue, karena statusnya sudah pasti expired
        $formatted = $tickets->map(function ($ticket) {
            return [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'service' => $ticket->service,
                'requester' => $ticket->requester,
                'form_data' => $ticket->form_data,
                'schedule_start' => $ticket->schedule_start,
                'schedule_end' => $ticket->schedule_end,
                'status' => 'expired', // Hardcode status untuk frontend
                'created_at' => $ticket->created_at,
            ];
        });

        return response()->json($formatted);
    }
    
    // ✅ FITUR BARU: ADMIN JUGA BISA DISPOSE
        public function assignStaff(Request $request, $ticket_id)
        {
            $request->validate(['staff_id' => 'required|exists:users,id']);

            $ticket = Ticket::find($ticket_id);
            $staff = User::find($request->staff_id);

            if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);

            if (!in_array($ticket->status, ['pending', 'queued', 'approved_admin', 'needs_reschedule'])) {
                return response()->json([
                    'message' => 'Tiket ini tidak dapat didisposisi (status saat ini: ' . $ticket->status . ').'
                ], 422);
            }

            if (($staff->role ?? '') !== 'staff') {
                return response()->json([
                    'message' => 'Penugasan hanya dapat ditujukan kepada akun dengan role Staff.'
                ], 422);
            }

            $ticketCategory = strtolower($ticket->service->category ?? '');
            if (!in_array($ticketCategory, $staff->service_access ?? [])) {
                return response()->json([
                    'message' => 'Staff ini tidak memiliki hak akses untuk kategori layanan ' . ($ticket->service->category_label ?? $ticketCategory) . '.'
                ], 422);
            }

            if (strtolower($ticket->service->category ?? '') === 'zoom' && $ticket->schedule_start && $ticket->schedule_end) {
                $totalLinks = \App\Models\ZoomLink::count();

                $activeBookings = Ticket::where('id', '!=', $ticket->id)
                    ->whereHas('service', function ($q) {
                        $q->where('category', 'zoom');
                    })
                    ->whereIn('status', ['assigned', 'in_progress', 'approved_admin'])
                    ->whereNotNull('schedule_start')
                    ->whereNotNull('schedule_end')
                    ->where(function ($query) use ($ticket) {
                        $query->where('schedule_start', '<', $ticket->schedule_end)
                            ->where('schedule_end', '>', $ticket->schedule_start);
                    })
                    ->count();

                if ($activeBookings >= $totalLinks) {
                    return response()->json([
                        'message' => "Gagal. Kuota link Zoom untuk jadwal ini sudah penuh ({$activeBookings} tiket disetujui, {$totalLinks} link tersedia). Silakan tolak pengajuan ini atau koordinasikan penjadwalan ulang dengan pemohon.",
                    ], 422);
                }
            }

            $status = strtolower(trim($staff->attendance_status ?? ''));
            if (in_array($status, ['cuti', 'sakit', 'izin', 'berhalangan hadir'])) {
                return response()->json([
                    'message' => 'Gagal. Staff sedang berhalangan hadir sehingga tidak dapat menerima tugas.'
                ], 422);
            }

            $hasActiveLeave = \App\Models\Leave::where('user_id', $staff->id)
                ->whereIn('status', ['pending', 'active'])
                ->whereDate('start_date', '<=', now()->toDateString())
                ->whereDate('end_date', '>=', now()->toDateString())
                ->exists();

            if ($ticket->schedule_start && $ticket->schedule_end) {
                $scheduleStart = \Carbon\Carbon::parse($ticket->schedule_start, 'Asia/Makassar')->toDateString();
                $scheduleEnd = \Carbon\Carbon::parse($ticket->schedule_end, 'Asia/Makassar')->toDateString();

                $leaveConflict = \App\Models\Leave::where('user_id', $staff->id)
                    ->whereIn('status', ['pending', 'active'])
                    ->whereDate('start_date', '<=', $scheduleEnd)
                    ->whereDate('end_date', '>=', $scheduleStart)
                    ->exists();

                if ($leaveConflict) {
                    return response()->json([
                        'message' => 'Gagal. Staff memiliki izin/cuti yang beririsan dengan jadwal pelaksanaan layanan.'
                    ], 422);
                }
            }

            if ($hasActiveLeave) {
                return response()->json([
                    'message' => 'Gagal. Staff memiliki pengajuan izin/cuti/sakit yang sedang berlaku hari ini.'
                ], 422);
            }

            $ticket->assigned_staff_id = $staff->id;
            $ticket->status = 'assigned';
            $ticket->save();

            if (in_array(strtolower($ticket->service->category ?? ''), ['zoom', 'command_center']) && $ticket->schedule_start) {
                if (now()->gt($ticket->schedule_start)) {
                    $telatMenit = now()->diffInMinutes($ticket->schedule_start);
                    \App\Models\TicketLog::create([
                        'ticket_id' => $ticket->id,
                        'user_id' => auth()->id(),
                        'action' => 'LATE_DISPOSED',
                        'description' => "Disposisi dilakukan terlambat {$telatMenit} menit dari jadwal mulai.",
                        'created_at' => now(),
                    ]);
                }
            }

            $roleLabel = 'Pimpinan';
            $disposerName = auth()->user()->name;

            \App\Models\TicketLog::create([
                'ticket_id' => $ticket->id,
                'user_id' => auth()->id(),
                'action' => 'DISPOSED',
                'description' => "Disposisi telah dilakukan oleh {$roleLabel} ({$disposerName}).",
                'created_at' => now(),
            ]);

            \App\Jobs\SendTelegramJob::dispatch(
                "📋 *DISPOSISI TIKET BARU*\n━━━━━━━━━━━━━━━━━━━\nTicket: #{$ticket->ticket_number}\nLayanan: {$ticket->service->name}\nDitunjuk oleh: *{$disposerName} ({$roleLabel})*\nDitugaskan ke: *{$staff->name}*\n━━━━━━━━━━━━━━━━━━━\n_Silakan cek aplikasi untuk memproses._"
            );

            return response()->json([
                'message' => "Berhasil menunjuk {$staff->name}.",
                'data' => $ticket->load(['service', 'staff', 'requester', 'zoomLink'])
            ]);
        }

    /**
     * GET /admin/dispositions/staff/{service_id}
     * Admin melihat list staf yang bisa ditunjuk untuk layanan tertentu
     */
    public function getAvailableStaffByService($service_id)
    {
        $service = \App\Models\Service::find($service_id);
        if (!$service) {
            return response()->json(['message' => 'Layanan tidak ditemukan'], 404);
        }

        $category = strtolower($service->category); 

        $allStaff = \App\Models\User::where('role', 'staff')
            ->whereRaw("service_access @> ?", ['["' . $category . '"]'])
            ->get(['id', 'name', 'nip', 'bidang', 'attendance_status']);

        $formatted = $allStaff->map(function($staff) {
            $status = strtolower(trim($staff->attendance_status ?? ''));
            $isAbsent = in_array($status, ['cuti', 'sakit', 'izin', 'berhalangan hadir']);
            $displayStatus = $staff->attendance_status;
            
            // ✅ FIX KESENJANGAN: Cek ke tabel leaves jika status di user masih "Masuk"
            // Ini untuk mengantisipasi kalau Admin belum klik Approve
            if (!$isAbsent) {
                $activeLeave = \App\Models\Leave::where('user_id', $staff->id)
                    ->whereIn('status', ['pending', 'active'])
                    ->whereDate('start_date', '<=', now()->toDateString())
                    ->whereDate('end_date', '>=', now()->toDateString())
                    ->first();

                if ($activeLeave) {
                    $isAbsent = true;
                    $displayStatus = $activeLeave->type; // Ambil status sebenarnya (Cuti/Sakit/Izin)
                }
            }
            
            return [
                'id' => $staff->id,
                'name' => $staff->name,
                'role' => 'staff',
                'nip' => $staff->nip,
                'bidang' => $staff->bidang ?? '-',
                'attendance_status' => $displayStatus,
                'active_task_count' => $staff->active_task_count ?? 0,
                'is_overloaded' => $staff->is_overloaded, // ✅ TAMBAHKAN INI (FLAG UNTUK FE)
                'is_available' => !$isAbsent, 
                'is_absent' => $isAbsent,
                'absent_reason' => $isAbsent ? "Sedang {$displayStatus}" : null
            ];
        });

        return response()->json($formatted);
    }

    // 1. Approve Pengajuan Izin/Cuti/Sakit
public function approveLeave($leave_id)
{
    $leave = \App\Models\Leave::find($leave_id);
    if (!$leave) return response()->json(['message' => 'Pengajuan tidak ditemukan'], 404);

    if ($leave->status !== 'pending') {
        return response()->json(['message' => 'Pengajuan ini sudah diproses sebelumnya.'], 403);
    }

    // Ubah status leave jadi aktif
    $leave->status = 'active';
    $leave->save();

    // ✅ SINIKRONKAN: Ubah attendance_status user
    $leave->user()->update([
        'attendance_status' => $leave->type // Otomatis jadi "Cuti", "Sakit", atau "Izin"
    ]);

    return response()->json([
        'message' => "Pengajuan {$leave->type} untuk {$leave->user->name} berhasil disetujui.",
        'data' => $leave
    ]);
}

// 2. Reject / Tolak Pengajuan
public function rejectLeave($leave_id)
{
    $leave = \App\Models\Leave::find($leave_id);
    if (!$leave) return response()->json(['message' => 'Pengajuan tidak ditemukan'], 404);

    if ($leave->status !== 'pending') {
        return response()->json(['message' => 'Pengajuan ini sudah diproses sebelumnya.'], 403);
    }

    $leave->status = 'cancelled';
    $leave->save();

    // Cek apakah user punya cuti/izin aktif lainnya
    $hasOtherActiveLeave = \App\Models\Leave::where('user_id', $leave->user_id)
        ->where('id', '!=', $leave->id)
        ->where('status', 'active')
        ->whereDate('end_date', '>=', now()->toDateString())
        ->exists();

    // Kalau tidak ada cuti aktif lain, kembalikan status ke Masuk
    if (!$hasOtherActiveLeave) {
        $leave->user()->update(['attendance_status' => 'Masuk']);
    }

    return response()->json([
        'message' => "Pengajuan {$leave->type} untuk {$leave->user->name} berhasil ditolak."
    ]);
}

    public function rejectTicket(Request $request, $ticket_id)
    {
        $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        $ticket = \App\Models\Ticket::with(['service', 'requester'])->find($ticket_id);
        
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);

        if (in_array($ticket->status, ['completed', 'cancelled', 'rejected', 'expired'])) {
            return response()->json([
                'message' => 'Tiket dengan status ini tidak dapat ditolak lagi.'
            ], 422);
        }

        if (trim($request->reason) === '') {
            return response()->json(['message' => 'Alasan penolakan wajib diisi.'], 422);
        }

        // ✅ FIX: Lepaskan kunci Zoom Link jika tiket ditolak admin
        if ($ticket->zoom_link_id) {
            \App\Models\ZoomLink::where('id', $ticket->zoom_link_id)->update([
                'status' => 'available', 
                'used_by_ticket_id' => null
            ]);
            $ticket->zoom_link_id = null;
        }

        $ticket->status = 'rejected';
        $ticket->rejection_reason = trim($request->reason);
        $ticket->save();

        \App\Models\TicketLog::create([
            'ticket_id' => $ticket->id, 
            'user_id' => auth()->id(),
            'action' => 'REJECTED_BY_LEADER', 
            'description' => "Admin menolak layanan. Alasan: {$request->reason}", 
            'created_at' => now(),
        ]);

        $opdChatId = $ticket->requester->telegram_chat_id ?? null;
        if ($opdChatId) {
            \App\Jobs\SendTelegramJob::dispatch(
                "❌ *Layanan Ditolak*\n━━━━━━━━━━━━━━━━━━━\nTicket : #{$ticket->ticket_number}\nLayanan: {$ticket->service->name}\nAlasan : {$request->reason}\n━━━━━━━━━━━━━━━━━━━\n_Silakan buat pengajuan baru jika sudah memperbaiki sesuai ketentuan._", 
                $opdChatId
            );
        }

        return response()->json([
            'message' => 'Layanan berhasil ditolak.',
            'data' => $ticket->load(['service', 'requester'])
        ]);
    }
}