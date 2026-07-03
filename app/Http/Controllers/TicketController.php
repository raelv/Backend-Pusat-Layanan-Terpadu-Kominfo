<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\Service;
use App\Models\User;
use App\Models\TicketLog;
use App\Jobs\SendTelegramJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\TicketsExport;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpWord\PhpWord;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Ticket::with(['service', 'staff', 'requester', 'comments.user']);

        if ($user->role === 'admin') {
            // Admin lihat semua
        } elseif ($user->role === 'staff') {
            $query->where('assigned_staff_id', $user->id)
                  ->orWhere(function($q) {
                      $q->whereNull('assigned_staff_id')
                        ->whereIn('status', ['pending', 'queued']);
                  });
        } else {
            $query->where(function($q) use ($user) {
                $q->where('user_id', $user->id) 
                  ->orWhereIn('status', ['pending', 'queued']);
            });
        }

        if ($request->has('service_type')) {
            $type = $request->service_type;
            $query->whereHas('service', function ($q) use ($type) {
                if ($type === 'it') {
                    $q->where('category', 'it');
                } elseif ($type === 'zoom') {
                    $q->where('category', 'zoom');
                } elseif ($type === 'command_center') {
                    $q->where('category', 'command_center');
                }
            });
        }

        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = $request->search;
            
            if (is_numeric($searchTerm)) {
                $query->where('ticket_number', (int)$searchTerm);
            } else {
                $query->where(function($q) use ($searchTerm) {
                    $q->whereHas('requester', function($q2) use ($searchTerm) {
                        $q2->where('name', 'ILIKE', "%{$searchTerm}%");
                    })
                    ->orWhereHas('service', function($q2) use ($searchTerm) {
                        $q2->where('name', 'ILIKE', "%{$searchTerm}%");
                    })
                    ->orWhere('form_data', 'ILIKE', "%{$searchTerm}%");
                });
            }
        }

        return $query->orderBy('created_at', 'desc')->paginate(15);
    }

    public function getActiveSchedules()
    {
        $schedules = Ticket::with(['service', 'staff'])
            ->whereHas('service', function ($q) {
                $q->whereIn('category', ['zoom', 'command_center']);
            })
            ->whereIn('status', ['assigned', 'in_progress', 'approved_admin'])
            ->whereNotNull('schedule_start')
            ->whereNotNull('schedule_end')
            ->orderBy('schedule_start', 'asc')
            ->get();

        return response()->json($schedules);
    }

    public function show($id)
    {
        $user = Auth::user();

        $ticket = Ticket::with([
            'service', 
            'staff', 
            'requester', 
            'comments' => function($query) use ($id) {
                $query->where('ticket_id', $id);
            },
            'comments.user'
        ])->find($id);

        if (!$ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        if ($user->role === 'opd' && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki izin untuk melihat tiket ini.'], 403);
        }

        return response()->json($ticket);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        $service = Service::find($request->service_id);
        $isScheduleBased = $service->is_schedule_based;

        // ✅ FIX BUG: Hapus tanda "!" agar Zoom & CC juga diblokir di luar jam operasional
        if ($user->role === 'opd') {
            $now = \Carbon\Carbon::now('Asia/Makassar');
            $startTime = \Carbon\Carbon::createFromTime(7, 30, 0, 'Asia/Makassar'); 
            $bufferTime = \Carbon\Carbon::createFromTime(21, 55, 0, 'Asia/Makassar');

            if ($now->lt($startTime) || $now->gt($bufferTime)) {
                return response()->json([
                    'message' => 'Pengajuan layanan sedang ditutup. Jam operasional layanan adalah 07:30 - 22:00.'
                ], 403);
            }
        }

        $validationRules = [
            'service_id' => 'required|exists:services,id',
            'form_data' => 'required', 
            'surat_permohonan' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'lampiran_tambahan' => 'nullable|file|mimes:pdf,jpg,jpeg,png,docx,xlsx,zip|max:10240',
        ];

        if ($isScheduleBased) {
            $validationRules['schedule_start'] = 'required|date';
            $validationRules['schedule_end'] = 'required|date|after:schedule_start';
        } else {
            $validationRules['schedule_start'] = 'nullable|date';
        }

        $request->validate($validationRules);
        
        $formData = $request->form_data;
        if (is_string($formData)) $formData = json_decode($formData, true);

        $suratPath = null;
        $lampiranPath = null;

        try {
            if ($request->hasFile('surat_permohonan')) {
                $suratPath = $request->file('surat_permohonan')->store('surat_permohonan', 'public');
            }
            if ($request->hasFile('lampiran_tambahan')) {
                $lampiranPath = $request->file('lampiran_tambahan')->store('lampiran_tambahan', 'public');
            }
        } catch (\Exception $e) {
            if ($suratPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($suratPath);
            return response()->json(['message' => 'Gagal mengupload file surat permohonan.', 'error' => $e->getMessage()], 500);
        }

        $dueDate = null;
        if ($isScheduleBased && $service->sla_days > 0) {
            $dueDate = now()->addDays($service->sla_days);
        }

        if ($isScheduleBased && $request->has('schedule_start')) {
            $nowWita = \Carbon\Carbon::now('Asia/Makassar');
            $newStart = \Carbon\Carbon::parse($request->schedule_start, 'Asia/Makassar');
            $newEnd = \Carbon\Carbon::parse($request->schedule_end, 'Asia/Makassar');
            
            if ($newStart->lt($nowWita)) {
                if ($suratPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($suratPath);
                if ($lampiranPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($lampiranPath);
                return response()->json(['message' => 'Tidak dapat melakukan pemesanan untuk jadwal yang sudah lewat.'], 422);
            }
            
            $opsStartTimeStr = '07:30';
            $opsEndTimeStr = '22:00';

            if ($newStart->format('H:i') < $opsStartTimeStr || $newEnd->format('H:i') > $opsEndTimeStr) {
                if ($suratPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($suratPath);
                if ($lampiranPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($lampiranPath);
                return response()->json(['message' => 'Jam pelaksanaan yang dipilih di luar jam operasional (07:30 - 22:00).'], 422);
            }

            $isConflict = Ticket::whereHas('service', function ($q) {
                $q->whereIn('category', ['zoom', 'command_center']);
            })
            ->whereIn('status', ['assigned', 'in_progress', 'approved_admin'])
            ->whereNotNull('schedule_start')
            ->whereNotNull('schedule_end')
            ->whereDate('schedule_start', $newStart->toDateString())
            ->where(function ($query) use ($newStart, $newEnd) {
                $query->where('schedule_start', '<', $newEnd)
                      ->where('schedule_end', '>', $newStart);
            })->exists();

            if ($isConflict) {
                if ($suratPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($suratPath);
                if ($lampiranPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($lampiranPath);
                return response()->json(['message' => 'Gagal mengajukan. Jadwal yang dipilih beririsan dengan layanan lain yang sudah terdaftar.'], 422);
            }
        }

        $ticket = Ticket::create([
            'service_id' => $request->service_id,
            'user_id' => $user->id, 
            'form_data' => $formData,
            'surat_permohonan_path' => $suratPath,
            'lampiran_tambahan_path' => $lampiranPath,
            'status' => 'pending', 
            'schedule_start' => $request->schedule_start,
            'schedule_end' => $request->schedule_end ?? null, 
            'due_date' => $dueDate, 
        ]);
        
        $ticket->ticket_number = $ticket->id;
        $ticket->save();

        TicketLog::create([
            'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
            'action' => 'CREATED', 'description' => 'Tiket layanan baru berhasil dibuat dan masuk ke sistem.', 'created_at' => now(),
        ]);

        $opdName = $user->name ?? 'Instansi OPD';
        $categoryLabel = $service->category_label;
        SendTelegramJob::dispatch("📢 *LAYANAN BARU TERSEDIA*\n└─ Instansi: *{$opdName}*\n└─ Layanan: {$categoryLabel}\n└─ Ticket: #{$ticket->ticket_number}\n_Silakan Staff yang memiliki jadwal kosong membuka Web Application._");
        
        return response()->json(['message' => 'Tiket berhasil dibuat', 'data' => $ticket->load(['service', 'requester'])], 201);
    }

    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $ticket = Ticket::find($id);

        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        if ($ticket->user_id !== $user->id) return response()->json(['message' => 'Hanya pemohon yang bisa mengubah permohonan ini.'], 403);
        if ($ticket->status === 'cancelled') return response()->json(['message' => 'Tiket yang sudah dibatalkan tidak dapat diubah.'], 403);
        
        if (!in_array($ticket->status, ['pending', 'queued', 'needs_reschedule'])) {
            return response()->json(['message' => 'Tiket sudah diproses. Perubahan hanya bisa dilakukan melalui ruang diskusi.'], 403);
        }

        $request->validate([
            'form_data' => 'required', 
            'schedule_start' => 'nullable|date',
            'schedule_end' => 'nullable|date|after:schedule_start',
            'surat_permohonan' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'lampiran_tambahan' => 'nullable|file|mimes:pdf,jpg,jpeg,png,docx,xlsx,zip|max:10240',
        ]);

        $formData = $request->form_data;
        if (is_string($formData)) $formData = json_decode($formData, true);

        if ($request->hasFile('surat_permohonan')) {
            if ($ticket->surat_permohonan_path) \Illuminate\Support\Facades\Storage::disk('public')->delete($ticket->surat_permohonan_path);
            $ticket->surat_permohonan_path = $request->file('surat_permohonan')->store('surat_permohonan', 'public');
        }

        if ($request->hasFile('lampiran_tambahan')) {
            if ($ticket->lampiran_tambahan_path) \Illuminate\Support\Facades\Storage::disk('public')->delete($ticket->lampiran_tambahan_path);
            $ticket->lampiran_tambahan_path = $request->file('lampiran_tambahan')->store('lampiran_tambahan', 'public');
        }

        $ticket->form_data = $formData;
        if ($request->has('schedule_start')) $ticket->schedule_start = $request->schedule_start;
        if ($request->has('schedule_end')) $ticket->schedule_end = $request->schedule_end;

        if ($ticket->status === 'needs_reschedule' && ($ticket->isDirty('schedule_start') || $ticket->isDirty('schedule_end'))) {
            
            $newStart = \Carbon\Carbon::parse($ticket->schedule_start, 'Asia/Makassar');
            $newEnd = \Carbon\Carbon::parse($ticket->schedule_end, 'Asia/Makassar');

            $isConflict = Ticket::where('id', '!=', $ticket->id)
                ->whereHas('service', function ($q) {
                    $q->whereIn('category', ['zoom', 'command_center']);
                })
                ->whereIn('status', ['assigned', 'in_progress', 'approved_admin'])
                ->whereNotNull('schedule_start')
                ->whereNotNull('schedule_end')
                ->where(function ($query) use ($newStart, $newEnd) {
                    $query->where('schedule_start', '<', $newEnd)
                          ->where('schedule_end', '>', $newStart);
                })->exists();

            if ($isConflict) {
                return response()->json([
                    'message' => 'Gagal mengubah jadwal. Jadwal baru yang kamu pilih beririsan dengan layanan lain yang sudah terdaftar.'
                ], 422);
            }

            $ticket->status = 'pending';
            
            TicketLog::create([
                'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
                'action' => 'RESCHEDULED', 'description' => 'OPD mengubah jadwal pelaksanaan dan tiket dikembalikan ke antrian.', 'created_at' => now(),
            ]);
        }

        $ticket->save();
        return response()->json(['message' => 'Permohonan berhasil diperbarui', 'data' => $ticket]);
    }

    public function updateStatus(Request $request, $detail_id)
    {
        $request->validate([
            'status' => 'required|in:pending,queued,approved_admin,approved_by_opd,pending_opd_approval,assigned,in_progress,completed,rejected,rejected_by_opd,cancelled,expired,overdue_schedule,needs_reschedule'
        ]);
        
        $user = Auth::user();
        $ticket = Ticket::find($detail_id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        
        if ($request->status === 'approved_by_opd' && $user->role === 'opd') {
            if ($ticket->user_id !== $user->id) return response()->json(['message' => 'Akses ditolak.'], 403);
            if ($ticket->status !== 'pending_opd_approval') return response()->json(['message' => 'Tidak bisa menyetujui.'], 422);
            
            $ticket->status = 'assigned'; 
            $ticket->save();

            TicketLog::create([
                'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
                'action' => 'APPROVED', 'description' => 'Estimasi waktu SLA disetujui oleh instansi pemohon.', 'created_at' => now(),
            ]);
            return response()->json(['message' => 'Estimasi disetujui OPD.', 'data' => $ticket->load(['service', 'staff', 'requester'])]);
        }

        if ($request->status === 'rejected_by_opd' && $user->role === 'opd') {
            if ($ticket->user_id !== $user->id) return response()->json(['message' => 'Akses ditolak.'], 403);
            if ($ticket->status !== 'pending_opd_approval') return response()->json(['message' => 'Tidak bisa menolak.'], 422);
            
            $ticket->status = 'rejected'; 
            $ticket->assigned_staff_id = null;
            $ticket->save();

            TicketLog::create([
                'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
                'action' => 'REJECTED', 'description' => 'Estimasi waktu SLA ditolak oleh instansi pemohon.', 'created_at' => now(),
            ]);

            SendTelegramJob::dispatch("❌ *ESTIMASI DITOLAK OPD*\n└─ Ticket: #{$ticket->ticket_number}\n└─ Oleh: {$user->name}\n_Tiket dikembalikan ke antrian._");
            
            $opdChatId = $ticket->requester->telegram_chat_id ?? null;
            SendTelegramJob::dispatch("❌ *Penolakan Berhasil Dicatat*\n└─ Ticket: #{$ticket->ticket_number}\n_Estimasi yang kamu tolak telah membatalkan tiket dari antrian staf._", $opdChatId);
            
            return response()->json(['message' => 'Estimasi ditolak.', 'data' => $ticket->load(['service', 'staff', 'requester'])]);
        }

        if ($request->status === 'cancelled' && $user->role === 'opd') {
            if ($ticket->user_id !== $user->id) return response()->json(['message' => 'Akses ditolak.'], 403);
            if (!in_array($ticket->status, ['pending', 'queued', 'pending_opd_approval'])) return response()->json(['message' => 'Gagal membatalkan.'], 403);
            
            $ticket->status = 'cancelled';
            $ticket->save();

            TicketLog::create([
                'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
                'action' => 'CANCELLED', 'description' => 'Tiket dibatalkan oleh pemohon.', 'created_at' => now(),
            ]);
            return response()->json(['message' => 'Permohonan berhasil dibatalkan', 'data' => $ticket]);
        }

        if ($user->role === 'admin') return response()->json(['message' => 'Akses ditolak.'], 403);
        if ($user->role === 'staff' && $ticket->assigned_staff_id !== $user->id) return response()->json(['message' => 'Akses ditolak.'], 403);
        
        if ($request->status === 'completed') {
            $isScheduleBased = $ticket->service->is_schedule_based;
            
            if ($isScheduleBased && $ticket->schedule_start) {
                $now = \Carbon\Carbon::now('Asia/Makassar');
                $startTime = \Carbon\Carbon::parse($ticket->schedule_start, 'Asia/Makassar');
                if ($now->lt($startTime)) return response()->json(['message' => 'Layanan belum dimulai dan belum dapat diselesaikan.'], 422);
            }
            $ticket->completed_at = now();
        }

        $ticket->status = $request->status;
        $ticket->save();

        if ($request->status === 'in_progress') {
            TicketLog::create([
                'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
                'action' => 'IN_PROGRESS', 'description' => 'Staff memulai pengerjaan tiket.', 'created_at' => now(),
            ]);
        }

        if ($request->status === 'completed') {
            TicketLog::create([
                'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
                'action' => 'COMPLETED', 'description' => 'Staff menyelesaikan tiket layanan.', 'created_at' => now(),
            ]);

            $opdChatId = $ticket->requester->telegram_chat_id ?? null;
            SendTelegramJob::dispatch(
                "✅ *Layanan Telah Selesai*\n━━━━━━━━━━━━━━━━━━━\nTicket : #{$ticket->ticket_number}\n━━━━━━━━━━━━━━━━━━━\n_Silakan membuka Website untuk melihat detail layanan dan mengisi Survei Kepuasan Masyarakat (SKM)._", 
                $opdChatId
            );
        }

        return response()->json(['message' => 'Status tiket berhasil diperbarui', 'data' => $ticket]);
    }

    public function claimTicket(Request $request, $id)
    {
        $user = Auth::user();
        if ($user->attendance_status !== 'Masuk') return response()->json(['message' => 'Gagal mengambil tugas. Status kehadiran Anda saat ini tidak "Masuk".'], 403);

        // ✅ TAMBAHKAN 'requester' DI SINI
        $ticket = Ticket::with(['service', 'requester'])->find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        if (!is_null($ticket->assigned_staff_id)) return response()->json(['message' => 'Tiket sudah diambil staf lain.'], 403);
        
        $isScheduleBased = $ticket->service->is_schedule_based;

        if (!$isScheduleBased) {
            $request->validate(['estimated_days' => 'required|integer|min:1|max:30']);
            
            $ticket->estimated_days = $request->estimated_days;
            $ticket->due_date = now()->addDays($request->estimated_days);
            $ticket->assigned_staff_id = $user->id;
            $ticket->status = 'pending_opd_approval';
            $ticket->save();

            TicketLog::create([
                'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
                'action' => 'ESTIMATION_SENT', 'description' => "Staff memberikan estimasi SLA pengerjaan selama {$request->estimated_days} hari.", 'created_at' => now(),
            ]);

            $opdChatId = $ticket->requester->telegram_chat_id ?? null;
            SendTelegramJob::dispatch(
                "📩 *Update Layanan Anda*\n━━━━━━━━━━━━━━━━━━━\nTicket : #{$ticket->ticket_number}\nStatus : Menunggu Persetujuan\nEstimasi: {$request->estimated_days} hari\nPetugas: {$user->name}\n━━━━━━━━━━━━━━━━━━━\n_Silakan buka website untuk menyetujui atau menolak estimasi waktu pengerjaan._", 
                $opdChatId
            );
            
            return response()->json(['message' => 'Estimasi berhasil dikirim. Menunggu persetujuan OPD.', 'data' => $ticket->load(['service', 'staff', 'requester'])]);
        }

        $ticket->assigned_staff_id = $user->id;
        $ticket->status = 'assigned';
        $ticket->save();

        TicketLog::create([
            'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
            'action' => 'CLAIMED', 'description' => 'Tiket jadwal berhasil diambil oleh staff.', 'created_at' => now(),
        ]);

        // ✅ FIX TAMBAHAN: KIRIM DM KE OPD SAAT STAFF AMBIL TIKET ZOOM/CC
        $opdChatId = $ticket->requester->telegram_chat_id ?? null;
        $categoryLabel = $ticket->service->category_label;
        $formattedSchedule = $ticket->schedule_start 
            ? \Carbon\Carbon::parse($ticket->schedule_start, 'Asia/Makassar')->format('d M Y, H:i') 
            : 'Belum ditentukan';

        SendTelegramJob::dispatch(
            "✅ *Layanan Anda Diambil Staff*\n" .
            "━━━━━━━━━━━━━━━━━━━\n" .
            "Ticket   : #{$ticket->ticket_number}\n" .
            "Layanan : {$categoryLabel}\n" .
            "Jadwal   : {$formattedSchedule}\n" .
            "Petugas  : *{$user->name}*\n" .
            "━━━━━━━━━━━━━━━━━━━\n" .
            "_Tunggu konfirmasi lebih lanjut dari staff._", 
            $opdChatId
        );
        
        return response()->json(['message' => 'Tiket berhasil diambil', 'data' => $ticket->load(['service', 'staff', 'requester'])]);
    }
    
    public function approveOrRejectTicket(Request $request, $id)
    {
        $request->validate(['action' => 'required|in:approve,reject']);
        $user = Auth::user();
        $ticket = Ticket::with('service', 'requester')->find($id);
        
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        
        if (!$ticket->service->is_schedule_based) return response()->json(['message' => 'Aksi ini hanya untuk layanan Zoom atau Command Center.'], 403);
        if (!is_null($ticket->assigned_staff_id)) return response()->json(['message' => 'Tiket sudah ditangani oleh staf lain.'], 403);

        if ($request->action === 'approve') {
            $newStart = \Carbon\Carbon::parse($ticket->schedule_start, 'Asia/Makassar');
            $newEnd = \Carbon\Carbon::parse($ticket->schedule_end, 'Asia/Makassar');

            $isConflict = Ticket::where('id', '!=', $ticket->id)
                ->whereHas('service', function ($q) {
                    $q->whereIn('category', ['zoom', 'command_center']);
                })
                ->whereIn('status', ['assigned', 'in_progress', 'approved_admin'])
                ->whereNotNull('schedule_start')
                ->whereNotNull('schedule_end')
                ->where(function ($query) use ($newStart, $newEnd) {
                    $query->where('schedule_start', '<', $newEnd)
                          ->where('schedule_end', '>', $newStart);
                })->exists();

            if ($isConflict) {
                $ticket->status = 'needs_reschedule';
                $ticket->save();

                TicketLog::create([
                    'ticket_id' => $ticket->id, 'user_id' => null,
                    'action' => 'SCHEDULE_CONFLICT', 'description' => 'Pengajuan ditolak sistem karena jadwal bentrok dengan layanan lain.', 'created_at' => now(),
                ]);

                $opdName = $ticket->requester->name ?? 'OPD';
                SendTelegramJob::dispatch("⚠️ *JADWAL BENTROK*\n└─ Ticket: #{$ticket->ticket_number}\n└─ Pemohon: {$opdName}\n_Status tiket diubah menjadi Perlu Penjadwalan Ulang._");

                $opdChatId = $ticket->requester->telegram_chat_id ?? null;
                SendTelegramJob::dispatch(
                    "⚠️ *Jadwal Layanan Anda Mengalami Bentrok*\n━━━━━━━━━━━━━━━━━━━\nTicket : #{$ticket->ticket_number}\n━━━━━━━━━━━━━━━━━━━\n_Silakan membuka Website untuk melakukan penjadwalan ulang._", 
                    $opdChatId
                );

                return response()->json([
                    'message' => 'Gagal menerima layanan. Jadwal yang diminta bentrok dengan layanan yang sudah aktif.',
                    'data' => $ticket->load(['service', 'staff', 'requester'])
                ], 422);
            }

            $ticket->assigned_staff_id = $user->id;
            $ticket->status = 'approved_admin';
            $ticket->save();

            TicketLog::create([
                'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
                'action' => 'SCHEDULE_APPROVED', 'description' => 'Staff menerima dan menyetujui jadwal layanan.', 'created_at' => now(),
            ]);

            $opdChatId = $ticket->requester->telegram_chat_id ?? null;
            SendTelegramJob::dispatch(
                "✅ *Layanan Anda Telah Diterima*\n━━━━━━━━━━━━━━━━━━━\nTicket : #{$ticket->ticket_number}\nStaff  : *{$user->name}*\nStatus : Sedang Diproses\n━━━━━━━━━━━━━━━━━━━\n", 
                $opdChatId
            );

            return response()->json(['message' => 'Layanan berhasil Diterima', 'data' => $ticket->load(['service', 'staff', 'requester'])]);
        } else {
            $ticket->status = 'rejected';
            $ticket->save();

            TicketLog::create([
                'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
                'action' => 'REJECTED', 'description' => 'Layanan ditolak oleh Staff.', 'created_at' => now(),
            ]);

            $opdChatId = $ticket->requester->telegram_chat_id ?? null;
            SendTelegramJob::dispatch(
                "❌ *Layanan Ditolak*\n━━━━━━━━━━━━━━━━━━━\nTicket : #{$ticket->ticket_number}\nAlasan : Ditolak oleh Staff.\n━━━━━━━━━━━━━━━━━━━\n", 
                $opdChatId
            );

            return response()->json(['message' => 'Layanan Ditolak', 'data' => $ticket->load(['service', 'staff', 'requester'])]);
        }
    }

    public function previewPdf($id)
    {
        $ticket = Ticket::with(['service', 'staff', 'requester'])->find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        return Pdf::loadView('pdf.bukti-layanan', compact('ticket'))->setPaper('A4', 'portrait')->stream('Bukti_Layanan_Ticket_'.$ticket->ticket_number.'.pdf');
    }

    public function downloadPdf($id)
    {
        $ticket = Ticket::with(['service', 'staff', 'requester'])->find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        return Pdf::loadView('pdf.bukti-layanan', compact('ticket'))->setPaper('A4', 'portrait')->download('Bukti_Layanan_Ticket_'.$ticket->ticket_number.'.pdf');
    }

    public function exportExcel()
    {
        return Excel::download(new TicketsExport, 'Rekap_Data_Tiket_Kominfo.xlsx');
    }

    public function exportWord($id)
    {
        $ticket = Ticket::with(['service', 'staff', 'requester'])->find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);

        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('BUKTI PENERIMAAN LAYANAN', ['bold' => true, 'size' => 16, 'alignment' => 'center']);
        $section->addText('Nomor: Ticket #' . $ticket->ticket_number, ['size' => 11, 'alignment' => 'center']);
        $section->addTextBreak(1);
        $section->addText('Hari / Tanggal    : ' . \Carbon\Carbon::parse($ticket->created_at)->translatedFormat('l, d F Y'));
        $section->addText('Jenis Layanan      : ' . ($ticket->service->name ?? 'N/A'));
        $section->addText('Status                : ' . strtoupper($ticket->status));
        $section->addText('Pelaksana Staf    : ' . ($ticket->staff->name ?? 'Belum Ditugaskan'));
        
        $fileName = 'Bukti_Layanan_Ticket_' . $ticket->ticket_number . '.docx';
        $tempFilePath = storage_path($fileName);
        $phpWord->save($tempFilePath, 'Word2007');
        return response()->download($tempFilePath)->deleteFileAfterSend(true);
    }
}