<?php

namespace App\Http\Controllers\Pimpinan;

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

            $rekapTugas = Ticket::select('services.category', DB::raw('count(*) as total'))
                ->join('services', 'tickets.service_id', '=', 'services.id')
                ->whereIn('services.category', ['IT', 'Zoom', 'Command Center'])
                ->groupBy('services.category')
                ->pluck('total', 'category')->toArray();

            $staffData = User::where('role', 'staff')->get()->map(function ($staff) {
                return [
                    'id' => $staff->id,
                    'name' => $staff->name,
                    'nip' => $staff->nip,
                    'bidang' => $staff->bidang ?? '-',
                    'bidangs' => $staff->bidangs ?? [],
                    'attendance_status' => $staff->attendance_status,
                    'active_task_count' => $staff->active_task_count,
                    'is_overloaded' => $staff->is_overloaded, 
                    'service_access' => $staff->service_access ?? []
                ];
            });

            return response()->json([
                'stats' => [
                    'total' => $totalTickets,
                    'completed' => $completedTickets,
                    'pending' => $pendingTickets,
                ],
                'rekap_tugas' => [
                    'it' => $rekapTugas['IT'] ?? 0,
                    'zoom' => $rekapTugas['Zoom'] ?? 0,
                    'command_center' => $rekapTugas['Command Center'] ?? 0,
                ],
                'staff' => $staffData
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal mengambil data dashboard', 'error' => $e->getMessage()], 500);
        }
    }

    public function getLeaves()
    {
        return \App\Models\Leave::with('user:id,name,role,attendance_status')->get();
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
            ->whereNotNull('schedule_end') // Pastikan ini layanan yang punya jam selesai (Zoom/CC)
            ->where('schedule_end', '<=', $now) // Jam selesai sudah lewat
            ->orderBy('created_at', 'desc') // Yang paling baru di atas
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

    // ✅ UPDATE: Log & Notif Dinamis
    public function assignStaff(Request $request, $ticket_id)
    {
        $request->validate(['staff_id' => 'required|exists:users,id']);

        $ticket = Ticket::find($ticket_id);
        $staff = User::find($request->staff_id);

        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        
        // ✅ PROTEKSI 1: Cek attendance_status di tabel users (case-insensitive)
        $status = strtolower(trim($staff->attendance_status ?? ''));
        if (in_array($status, ['cuti', 'sakit', 'izin', 'berhalangan hadir'])) {
            return response()->json([
                'message' => 'Gagal. Staff sedang berhalangan hadir sehingga tidak dapat menerima tugas.'
            ], 422);
        }

        // ✅ PROTEKSI 2: Cek langsung ke tabel leaves (Double Protection)
        $hasActiveLeave = \App\Models\Leave::where('user_id', $staff->id)
            ->whereIn('status', ['pending', 'active'])
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())
            ->exists();

        if ($hasActiveLeave) {
            return response()->json([
                'message' => 'Gagal. Staff memiliki pengajuan izin/cuti/sakit yang sedang berlaku hari ini.'
            ], 422);
        }

        $ticket->assigned_staff_id = $staff->id;
        $ticket->status = 'assigned';
        $ticket->save();

        // ✅ CATAT LOG AUDIT JIKA INI DISPOSISI TERLAMBAT (ZOOM / COMMAND CENTER)
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

        // ✅ Kirim ke Grup Staff (Otomatis ke TELEGRAM_CHAT_ID di .env)
        \App\Jobs\SendTelegramJob::dispatch(
            "📋 *DISPOSISI TIKET BARU*\n━━━━━━━━━━━━━━━━━━━\nTicket: #{$ticket->ticket_number}\nLayanan: {$ticket->service->name}\nDitunjuk oleh: *{$disposerName} ({$roleLabel})*\nDitugaskan ke: *{$staff->name}*\n━━━━━━━━━━━━━━━━━━━\n_Silakan cek aplikasi untuk memproses._"
        );

        return response()->json([
            'message' => "Berhasil menunjuk {$staff->name}.",
            'data' => $ticket->load(['service', 'staff', 'requester', 'zoomLink'])
        ]);
    }

    public function rejectTicket(Request $request, $ticket_id)
    {
        $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        $ticket = \App\Models\Ticket::with(['service', 'requester'])->find($ticket_id);
        
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);

        if (trim($request->reason) === '') {
            return response()->json(['message' => 'Alasan penolakan wajib diisi.'], 422);
        }

        // ✅ FIX: Lepaskan kunci Zoom Link jika tiket ditolak pimpinan
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
            'description' => "Pimpinan menolak layanan. Alasan: {$request->reason}", 
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