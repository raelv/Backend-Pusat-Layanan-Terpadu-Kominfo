<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\Service;
use App\Models\User;
use App\Models\TicketLog;
use App\Models\ZoomLink;
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
        // ✅ FIX: Ditambahkan zoomLink agar FE bisa baca status link di daftar tiket
        $query = Ticket::with(['service', 'staff', 'requester', 'zoomLink', 'comments.user']);

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

        // ✅ FIX UTAMA: Ditambahkan 'zoomLink' sesuai permintaan FE
        $ticket = Ticket::with([
            'service', 
            'staff', 
            'requester', 
            'zoomLink', 
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
        $category = strtolower($service->category);

        // ✅ REVISI JAM OPERASIONAL BERDASARKAN KATEGORI
        if ($user->role === 'opd') {
            $now = \Carbon\Carbon::now('Asia/Makassar');
            
            if ($category === 'command_center') {
                // COMMAND CENTER: Senin - Jumat, 07:30 - 16:00 WITA
                if ($now->isWeekend()) {
                    return response()->json([
                        'message' => 'Pengajuan layanan Command Center sedang ditutup. Hanya tersedia hari Senin - Jumat, pukul 07:30 - 16:00 WITA.'
                    ], 403);
                }
                $startTime = \Carbon\Carbon::createFromTime(7, 30, 0, 'Asia/Makassar');
                $endTime = \Carbon\Carbon::createFromTime(16, 0, 0, 'Asia/Makassar');
                
                if ($now->lt($startTime) || $now->gt($endTime)) {
                    return response()->json([
                        'message' => 'Pengajuan layanan Command Center sedang ditutup. Jam operasional adalah Senin - Jumat, pukul 07:30 - 16:00 WITA.'
                    ], 403);
                }
            } elseif ($category === 'it') {
                // LAYANAN IT: Setiap hari, 07:30 - 22:00 WITA (TIDAK DIUBAH)
                $startTime = \Carbon\Carbon::createFromTime(7, 30, 0, 'Asia/Makassar'); 
                $bufferTime = \Carbon\Carbon::createFromTime(21, 55, 0, 'Asia/Makassar');

                if ($now->lt($startTime) || $now->gt($bufferTime)) {
                    return response()->json([
                        'message' => 'Pengajuan layanan sedang ditutup. Jam operasional layanan adalah 07:30 - 22:00 WITA.'
                    ], 403);
                }
            }
            // ZOOM: Tidak ada batasan jam operasional (Skip pengecekan)
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

        // ✅ VALIDASI JUMLAH PESERTA COMMAND CENTER
        if (strtolower($service->category) === 'command_center') {
            $jumlahPeserta = isset($formData['jumlah_peserta']) ? (int)$formData['jumlah_peserta'] : null;
            
            if (is_null($jumlahPeserta)) {
                return response()->json([
                    'message' => 'Jumlah peserta wajib diisi untuk layanan Command Center.'
                ], 422);
            }

            if ($jumlahPeserta < 3 || $jumlahPeserta > 50) {
                return response()->json([
                    'message' => 'Jumlah peserta tidak boleh kurang dari 3 dan tidak boleh melebihi 50 orang (kapasitas maksimal Command Center).'
                ], 422);
            }
        }

        if (isset($formData['wa'])) {
            $wa = preg_replace('/\s+/', '', $formData['wa']);
            if (!preg_match('/^(\+62|62|08)[0-9]{8,12}$/', $wa)) {
                return response()->json([
                    'message' => 'Nomor WhatsApp tidak valid. Pastikan nomor berupa angka, diawali dengan 08/628, dan terdiri dari 10-14 digit.'
                ], 422);
            }
            $formData['wa'] = $wa;
        }

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

        if ($isScheduleBased) {
            $newStart = \Carbon\Carbon::parse($request->schedule_start, 'Asia/Makassar');
            $newEnd = \Carbon\Carbon::parse($request->schedule_end, 'Asia/Makassar');
            
            $durationInHours = $newStart->diffInHours($newEnd);
            if ($durationInHours > 6) {
                if ($suratPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($suratPath);
                if ($lampiranPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($lampiranPath);
                return response()->json(['message' => 'Pengajuan ditolak. Berdasarkan SOP, durasi maksimal pemesanan Zoom/Command Center adalah 6 jam.'], 422);
            }
            $dueDate = $newEnd;
        } else {
            $dueDateInput = $request->input('due_date');
            
            if ($dueDateInput) {
                $parsedDueDate = \Carbon\Carbon::parse($dueDateInput, 'Asia/Makassar');
                $diffInDays = $parsedDueDate->diffInDays(\Carbon\Carbon::now('Asia/Makassar'));
                
                if ($diffInDays < 90) {
                    if ($suratPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($suratPath);
                    if ($lampiranPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($lampiranPath);
                    return response()->json(['message' => 'Pengajuan ditolak. Berdasarkan SOP, pembuatan aplikasi website membutuhkan waktu minimal 3 Bulan (90 Hari).'], 422);
                }
                $dueDate = $parsedDueDate;
            } else {
                $estimasiHari = isset($formData['estimasi']) ? (int)$formData['estimasi'] : null;
                if ($estimasiHari !== null && $estimasiHari < 90) {
                    if ($suratPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($suratPath);
                    if ($lampiranPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($lampiranPath);
                    return response()->json(['message' => 'Pengajuan ditolak. Berdasarkan SOP, pembuatan aplikasi website membutuhkan waktu minimal 3 Bulan (90 Hari).'], 422);
                }
                $dueDate = now()->addMonths(3); 
            }
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
            
            if ($category === 'command_center') {
                if ($newStart->isWeekend()) {
                    if ($suratPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($suratPath);
                    if ($lampiranPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($lampiranPath);
                    return response()->json(['message' => 'Gagal mengajukan. Jadwal Command Center hanya tersedia hari Senin - Jumat.'], 422);
                }

                if ($newStart->format('H:i') < '07:30' || $newEnd->format('H:i') > '16:00') {
                    if ($suratPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($suratPath);
                    if ($lampiranPath) \Illuminate\Support\Facades\Storage::disk('public')->delete($lampiranPath);
                    return response()->json(['message' => 'Jam pelaksanaan Command Center yang dipilih di luar jam operasional (07:30 - 16:00 WITA).'], 422);
                }
            }

            $isConflict = Ticket::whereHas('service', function ($q) {
                $q->whereIn('category', ['zoom', 'command_center']);
            })
            ->whereIn('status', ['pending', 'assigned', 'in_progress', 'approved_admin'])
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
            'action' => 'CREATED', 'description' => 'Tiket layanan baru berhasil dibuat dan menunggu disposisi pimpinan.', 'created_at' => now(),
        ]);

        $opdName = $user->name ?? 'Instansi OPD';
        $categoryLabel = $service->category_label;
        
        // ✅ FIX: Kirim khusus ke Telegram Pimpinan, BUKAN Grup Staff
        $notifMessage = "📢 *LAYANAN BARU TERSEDIA*\n└─ Instansi: *{$opdName}*\n└─ Layanan: {$categoryLabel}\n└─ Ticket: #{$ticket->ticket_number}\n_Silakan Pimpinan untuk melakukan disposisi._";
        User::where('role', 'pimpinan')->whereNotNull('telegram_chat_id')->each(function($pimpinan) use ($notifMessage) {
            SendTelegramJob::dispatch($notifMessage, $pimpinan->telegram_chat_id);
        });
        
        return response()->json(['message' => 'Tiket berhasil dibuat', 'data' => $ticket->load(['service', 'requester'])], 201);
    }

    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $ticket = Ticket::find($id);

        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        if ($ticket->user_id !== $user->id) return response()->json(['message' => 'Hanya pemohon yang bisa mengubah permohonan ini.'], 403);
        if ($ticket->status === 'cancelled') return response()->json(['message' => 'Tiket yang sudah dibatalkan tidak dapat diubah.'], 403);
        
        if (!in_array($ticket->status, ['pending', 'queued', 'needs_reschedule', 'expired'])) {
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

        // ✅ VALIDASI JUMLAH PESERTA COMMAND CENTER
        $currentService = \App\Models\Service::find($ticket->service_id);
        if ($currentService && strtolower($currentService->category) === 'command_center') {
            $jumlahPeserta = isset($formData['jumlah_peserta']) ? (int)$formData['jumlah_peserta'] : null;
            
            if (is_null($jumlahPeserta)) {
                return response()->json([
                    'message' => 'Jumlah peserta wajib diisi untuk layanan Command Center.'
                ], 422);
            }

            if ($jumlahPeserta < 3 || $jumlahPeserta > 50) {
                return response()->json([
                    'message' => 'Jumlah peserta tidak boleh kurang dari 3 dan tidak boleh melebihi 50 orang (kapasitas maksimal Command Center).'
                ], 422);
            }
        }

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

        if (($ticket->status === 'needs_reschedule' || $ticket->status === 'expired') && ($ticket->isDirty('schedule_start') || $ticket->isDirty('schedule_end'))) {
            
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
        // ✅ FIX: Tambahkan zoomLink saat return
        return response()->json(['message' => 'Permohonan berhasil diperbarui', 'data' => $ticket->load('zoomLink')]);
    }

    public function updateStatus(Request $request, $detail_id)
    {
        $request->validate([
            'status' => 'required|in:pending,queued,approved_admin,assigned,in_progress,completed,rejected,cancelled,expired,overdue_schedule,needs_reschedule'
        ]);
        
        $user = Auth::user();
        $ticket = Ticket::find($detail_id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);

        if ($request->status === 'cancelled' && $user->role === 'opd') {
            if ($ticket->user_id !== $user->id) return response()->json(['message' => 'Akses ditolak.'], 403);
            if (!in_array($ticket->status, ['pending', 'queued'])) return response()->json(['message' => 'Gagal membatalkan.'], 403);
            
            // ✅ FIX: Lepaskan kunci Zoom Link kalau OPD membatalkan
            if ($ticket->zoom_link_id) {
                \App\Models\ZoomLink::where('id', $ticket->zoom_link_id)->update([
                    'status' => 'available', 
                    'used_by_ticket_id' => null
                ]);
                $ticket->zoom_link_id = null;
            }

            $ticket->status = 'cancelled';
            $ticket->save();

            TicketLog::create([
                'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
                'action' => 'CANCELLED', 'description' => 'Tiket dibatalkan oleh pemohon.', 'created_at' => now(),
            ]);
            return response()->json(['message' => 'Permohonan berhasil dibatalkan', 'data' => $ticket->load('zoomLink')]);
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

        // ✅ AUTO-RELEASE: Lepaskan kunci link Zoom jika tugas selesai/dibatalkan/ditolak
        if (in_array($request->status, ['completed', 'rejected', 'cancelled', 'expired']) && $ticket->zoom_link_id) {
            \App\Models\ZoomLink::where('id', $ticket->zoom_link_id)->update([
                'status' => 'available', 
                'used_by_ticket_id' => null
            ]);
            $ticket->zoom_link_id = null;
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

        // ✅ FIX: Tambahkan zoomLink saat return
        return response()->json(['message' => 'Status tiket berhasil diperbarui', 'data' => $ticket->load('zoomLink')]);
    }

    public function claimTicket(Request $request, $id)
    {
        $user = Auth::user();
        if ($user->attendance_status !== 'Masuk') return response()->json(['message' => 'Gagal mengambil tugas. Status kehadiran Anda saat ini tidak "Masuk".'], 403);

        $ticket = Ticket::with(['service', 'requester'])->find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        if (!is_null($ticket->assigned_staff_id)) return response()->json(['message' => 'Tiket sudah diambil staf lain.'], 403);
        
        $isScheduleBased = $ticket->service->is_schedule_based;

        $ticket->assigned_staff_id = $user->id;
        $ticket->status = 'assigned';
        $ticket->assigned_at = now();
        $ticket->save();

        TicketLog::create([
            'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
            'action' => 'CLAIMED', 'description' => 'Tiket berhasil diambil oleh staff.', 'created_at' => now(),
        ]);

        $opdChatId = $ticket->requester->telegram_chat_id ?? null;
        $categoryLabel = $ticket->service->category_label;
        
        $message = "✅ *Layanan Anda Diambil Staff*\n" .
            "━━━━━━━━━━━━━━━━━━━\n" .
            "Ticket   : #{$ticket->ticket_number}\n" .
            "Layanan : {$categoryLabel}\n" .
            "Petugas  : *{$user->name}*\n" .
            "━━━━━━━━━━━━━━━━━━━\n";

        if ($isScheduleBased && $ticket->schedule_start) {
            $formattedSchedule = \Carbon\Carbon::parse($ticket->schedule_start, 'Asia/Makassar')->format('d M Y, H:i');
            $message .= "Jadwal   : {$formattedSchedule}\n";
        }

        $message .= "_Tunggu konfirmasi lebih lanjut dari staff._";

        SendTelegramJob::dispatch($message, $opdChatId);
        
        // ✅ FIX: Tambahkan zoomLink saat return
        return response()->json(['message' => 'Tiket berhasil diambil', 'data' => $ticket->load(['service', 'staff', 'requester', 'zoomLink'])]);
    }
    
    public function approveOrRejectTicket(Request $request, $id)
    {
        $request->validate([
            'action' => 'required|in:approve,reject',
            'zoom_link_id' => 'nullable|exists:zoom_links,id', 
            'rejection_reason' => 'required_if:action,reject|string|max:500' 
        ]);
        
        $user = Auth::user();
        $ticket = Ticket::with('service', 'requester')->find($id);
        
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        
        if (!$ticket->service->is_schedule_based) return response()->json(['message' => 'Aksi ini hanya untuk layanan Zoom atau Command Center.'], 403);

        if (!is_null($ticket->assigned_staff_id) && $ticket->assigned_staff_id !== $user->id) {
            return response()->json(['message' => 'Akses ditolak. Tiket ini ditunjukan untuk staff lain.'], 403);
        }

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
                $notifBentrok = "⚠️ *JADWAL BENTROK*\n└─ Ticket: #{$ticket->ticket_number}\n└─ Pemohon: {$opdName}\n_Status tiket diubah menjadi Perlu Penjadwalan Ulang._";
                User::where('role', 'pimpinan')->whereNotNull('telegram_chat_id')->each(function($pimpinan) use ($notifBentrok) {
                    SendTelegramJob::dispatch($notifBentrok, $pimpinan->telegram_chat_id);
                });

                $opdChatId = $ticket->requester->telegram_chat_id ?? null;
                SendTelegramJob::dispatch(
                    "⚠️ *Jadwal Layanan Anda Mengalami Bentrok*\n━━━━━━━━━━━━━━━━━━━\nTicket : #{$ticket->ticket_number}\n━━━━━━━━━━━━━━━━━━━\n_Silakan membuka Website untuk melakukan penjadwalan ulang._", 
                    $opdChatId
                );

                return response()->json([
                    'message' => 'Gagal menerima layanan. Jadwal yang diminta bentrok dengan layanan yang sudah aktif.',
                    'data' => $ticket->load(['service', 'staff', 'requester', 'zoomLink'])
                ], 422);
            }

            if (strtolower($ticket->service->category) === 'zoom') {
                if (!$request->zoom_link_id) {
                    return response()->json(['message' => 'Wajib memilih link zoom untuk layanan ini.'], 422);
                }

                $zoomLink = \App\Models\ZoomLink::where('id', $request->zoom_link_id)
                    ->where('status', 'available')
                    ->lockForUpdate()
                    ->first();

                if (!$zoomLink) {
                    return response()->json(['message' => 'Link zoom sudah dipakai layanan lain atau tidak ditemukan. Pilih link lain.'], 422);
                }

                $ticket->zoom_link_id = $zoomLink->id;
                $zoomLink->update(['status' => 'in_use', 'used_by_ticket_id' => $ticket->id]);
            }

            $ticket->assigned_staff_id = $user->id;
            $ticket->status = 'in_progress'; 
            $ticket->save();

            TicketLog::create([
                'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
                'action' => 'IN_PROGRESS', 
                'description' => 'Staff menerima dan memulai pengerjaan layanan.', 
                'created_at' => now(),
            ]);

            $opdChatId = $ticket->requester->telegram_chat_id ?? null;
            
            $zoomText = "";
            if ($ticket->zoom_link_id) {
                $zoomText = "\nLink Zoom: {$zoomLink->link}\n";
            }

            SendTelegramJob::dispatch(
                "✅ *Layanan Anda Telah Diterima*\n━━━━━━━━━━━━━━━━━━━\nTicket : #{$ticket->ticket_number}\nStaff  : *{$user->name}*\nStatus : Sedang Diproses\n{$zoomText}━━━━━━━━━━━━━━━━━━━\n", 
                $opdChatId
            );

            return response()->json(['message' => 'Layanan diterima dan sedang dikerjakan.', 'data' => $ticket->load(['service', 'staff', 'zoomLink', 'requester'])]);
        } else {
            if ($ticket->zoom_link_id) {
                \App\Models\ZoomLink::where('id', $ticket->zoom_link_id)->update([
                    'status' => 'available', 
                    'used_by_ticket_id' => null
                ]);
                $ticket->zoom_link_id = null;
            }

            $ticket->status = 'rejected';
            $ticket->rejection_reason = $request->rejection_reason; 
            $ticket->save();

            TicketLog::create([
                'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
                'action' => 'REJECTED', 
                'description' => "Layanan ditolak oleh Staff. Alasan: " . ($request->rejection_reason ?? 'Tidak disebutkan'), 
                'created_at' => now(),
            ]);

            $opdChatId = $ticket->requester->telegram_chat_id ?? null;
            
            $reasonText = $request->rejection_reason ? "\nAlasan : {$request->rejection_reason}" : "";
            SendTelegramJob::dispatch(
                "❌ *Layanan Ditolak*\n━━━━━━━━━━━━━━━━━━━\nTicket : #{$ticket->ticket_number}{$reasonText}\n━━━━━━━━━━━━━━━━━━━\n", 
                $opdChatId
            );

            // ✅ FIX: Tambahkan zoomLink saat return
            return response()->json(['message' => 'Layanan Ditolak', 'data' => $ticket->load(['service', 'staff', 'requester', 'zoomLink'])]);
        }
    }

    public function processByStaff(Request $request, $id)
    {
        $user = Auth::user();
        $ticket = Ticket::with('service')->find($id);
        
        if (!$ticket || $ticket->assigned_staff_id !== $user->id) {
            return response()->json(['message' => 'Akses ditolak. Bukan tiket Anda.'], 403);
        }

        $request->validate([
            'action' => 'required|in:approve,reject',
            'rejection_reason' => 'required_if:action,reject|string|max:500',
            'zoom_link_id' => 'nullable|exists:zoom_links,id'
        ]);

        if ($request->action === 'reject') {
            if ($ticket->zoom_link_id) {
                ZoomLink::where('id', $ticket->zoom_link_id)->update([
                    'status' => 'available', 
                    'used_by_ticket_id' => null
                ]);
                $ticket->zoom_link_id = null;
            }

            $ticket->status = 'rejected';
            $ticket->rejection_reason = $request->rejection_reason;
            $ticket->assigned_staff_id = null; 
            $ticket->save();

            TicketLog::create([
                'ticket_id' => $ticket->id, 
                'user_id' => auth()->id(),
                'action' => 'REJECTED', 
                'description' => "Staff menolak. Alasan: {$request->rejection_reason}", 
                'created_at' => now(),
            ]);

            $opdChatId = $ticket->requester->telegram_chat_id ?? null;
            if ($opdChatId) {
                SendTelegramJob::dispatch(
                    "❌ *Layanan Ditolak*\n━━━━━━━━━━━━━━━━━━━\nTicket : #{$ticket->ticket_number}\nAlasan : {$request->rejection_reason}\n━━━━━━━━━━━━━━━━━━━\n", 
                    $opdChatId
                );
            }

            // ✅ FIX: Tambahkan zoomLink saat return
            return response()->json(['message' => 'Layanan berhasil ditolak.', 'data' => $ticket->load('zoomLink')]);
        }

        if ($request->action === 'approve') {
            if (strtolower($ticket->service->category) === 'zoom') {
                if (!$request->zoom_link_id) {
                    return response()->json(['message' => 'Wajib memilih link zoom untuk layanan ini.'], 422);
                }

                $zoomLink = ZoomLink::where('id', $request->zoom_link_id)
                    ->where('status', 'available')
                    ->lockForUpdate()
                    ->first();

                if (!$zoomLink) {
                    return response()->json(['message' => 'Link zoom sudah dipakai layanan lain atau tidak ditemukan. Pilih link lain.'], 422);
                }

                $ticket->zoom_link_id = $zoomLink->id;
                $zoomLink->update(['status' => 'in_use', 'used_by_ticket_id' => $ticket->id]);
            }

            $ticket->status = 'in_progress'; 
            $ticket->save();

            TicketLog::create([
                'ticket_id' => $ticket->id, 
                'user_id' => auth()->id(),
                'action' => 'IN_PROGRESS', 
                'description' => 'Staff menerima dan memulai pengerjaan.', 
                'created_at' => now(),
            ]);

            $opdChatId = $ticket->requester->telegram_chat_id ?? null;
            if ($opdChatId && $ticket->zoom_link_id) {
                SendTelegramJob::dispatch(
                    "✅ *Layanan Anda Telah Diterima*\n━━━━━━━━━━━━━━━━━━━\nTicket : #{$ticket->ticket_number}\nLink Zoom: {$zoomLink->link}\n━━━━━━━━━━━━━━━━━━━\n", 
                    $opdChatId
                );
            }

            return response()->json(['message' => 'Layanan diterima dan sedang dikerjakan.', 'data' => $ticket->load('zoomLink')]);
        }
    }

    public function previewPdf($id)
    {
        $ticket = Ticket::with(['service', 'staff', 'requester', 'zoomLink'])->find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        return Pdf::loadView('pdf.bukti-layanan', compact('ticket'))->setPaper('A4', 'portrait')->stream('Bukti_Layanan_Ticket_'.$ticket->ticket_number.'.pdf');
    }

    public function downloadPdf($id)
    {
        $ticket = Ticket::with(['service', 'staff', 'requester', 'zoomLink'])->find($id);
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

    public function getResubmitData($id)
    {
        $user = Auth::user();
        $ticket = Ticket::find($id);

        if (!$ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        if ($ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Akses ditolak. Hanya pemohon yang bisa mengajukan ulang.'], 403);
        }

        return response()->json([
            'service_id' => $ticket->service_id,
            'form_data' => $ticket->form_data
        ]);
    }
}