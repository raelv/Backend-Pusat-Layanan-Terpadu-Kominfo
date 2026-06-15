<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\Service;
use App\Models\User;
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

        // Filter Berdasarkan Role
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

        // Filter Berdasarkan Tipe Layanan
        if ($request->has('service_type')) {
            $type = $request->service_type;
            $query->whereHas('service', function ($q) use ($type) {
                if ($type === 'it') {
                    $q->where('name', 'LIKE', '%Aplikasi%');
                } elseif ($type === 'zoom') {
                    $q->where('name', 'LIKE', '%Zoom%');
                } elseif ($type === 'command_center') {
                    $q->where('name', 'LIKE', '%Command Center%');
                }
            });
        }

        // ==========================================
        // FITUR BARU: SEARCH DINAMIS (PRD TEMANMU)
        // ==========================================
        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = $request->search;
            
            if (is_numeric($searchTerm)) {
                // EXACT MATCH: Jika yang dicari angka, cari persis sama dengan ID Tiket
                $query->where('ticket_number', (int)$searchTerm);
            } else {
                // LIKE MATCH: Jika yang dicari huruf, cari di nama OPD, layanan, atau form data
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

        return $query->get();
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

        // ==========================================
        // 1. VALIDASI JAM OPERASIONAL UNTUK OPD
        // ==========================================
        if ($user->role === 'opd') {
            $now = \Carbon\Carbon::now();
            $startTime = \Carbon\Carbon::createFromTime(7, 30, 0); 
            $endTime = \Carbon\Carbon::createFromTime(9, 0, 0);   

            // Pengamanan: Cegah submit jika sudah melewati jam 21:55 (H-5 menit sebelum tutup)
            $bufferTime = \Carbon\Carbon::createFromTime(8, 55, 0);

            if ($now->lt($startTime) || $now->gt($bufferTime)) {
                return response()->json([
                    'message' => 'Pengajuan layanan sedang ditutup. Jam operasional layanan adalah 07:30 - 22:00.'
                ], 403);
            }
        }

        $request->validate([
            'service_id' => 'required|exists:services,id',
            'form_data' => 'required', 
            'schedule_start' => 'nullable|date',
        ]);
        
        $formData = $request->form_data;
        if (is_string($formData)) $formData = json_decode($formData, true);

        $service = Service::find($request->service_id);
        $isScheduleBased = str_contains(strtolower($service->name), 'zoom') || str_contains(strtolower($service->name), 'command');

        // ==========================================
        // 2. VALIDASI JADWAL BOOKING (ZOOM / COMMAND CENTER)
        // ==========================================
        if ($isScheduleBased && $request->has('schedule_start')) {
            $scheduleTime = \Carbon\Carbon::parse($request->schedule_start);
            $startTime = \Carbon\Carbon::createFromTime(7, 30, 0); 
            $endTime = \Carbon\Carbon::createFromTime(9, 0, 0);   

            if ($scheduleTime->lt($startTime) || $scheduleTime->gt($endTime)) {
                return response()->json([
                    'message' => 'Jam pelaksanaan yang dipilih di luar jam operasional (07:30 - 22:00). Silakan pilih jam lain.'
                ], 422);
            }
        }

        $dueDate = null;
        if ($isScheduleBased && $service->sla_days > 0) {
            $dueDate = now()->addDays($service->sla_days);
        }

        $ticket = Ticket::create([
            'service_id' => $request->service_id,
            'user_id' => $user->id, 
            'form_data' => $formData,
            'status' => 'pending', 
            'schedule_start' => $request->schedule_start,
            'due_date' => $dueDate, 
        ]);
        
        $ticket->ticket_number = $ticket->id;
        $ticket->save();
        
        return response()->json(['message' => 'Tiket berhasil dibuat', 'data' => $ticket->load(['service', 'requester'])], 201);
    }

    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $ticket = Ticket::find($id);

        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        if ($ticket->user_id !== $user->id) return response()->json(['message' => 'Hanya pemohon yang bisa mengubah permohonan ini.'], 403);
        if (!in_array($ticket->status, ['pending', 'queued'])) return response()->json(['message' => 'Tiket sudah diproses. Perubahan hanya bisa dilakukan melalui ruang diskusi.'], 403);

        $request->validate(['form_data' => 'required', 'schedule_start' => 'nullable|date']);
        $formData = $request->form_data;
        if (is_string($formData)) $formData = json_decode($formData, true);

        $ticket->form_data = $formData;
        if ($request->has('schedule_start')) $ticket->schedule_start = $request->schedule_start;
        $ticket->save();

        return response()->json(['message' => 'Permohonan berhasil diperbarui', 'data' => $ticket]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:pending,queued,approved_admin,assigned,in_progress,completed,rejected,cancelled,expired']);
        $user = Auth::user();
        $ticket = Ticket::find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        
        if ($request->status === 'cancelled' && $user->role === 'opd') {
            if ($ticket->user_id !== $user->id) return response()->json(['message' => 'Akses ditolak.'], 403);
            if (!in_array($ticket->status, ['pending', 'queued'])) return response()->json(['message' => 'Gagal membatalkan. Tiket yang sedang/d sudah diproses tidak bisa dibatalkan langsung.'], 403);
            $ticket->status = 'cancelled';
            $ticket->save();
            return response()->json(['message' => 'Permohonan berhasil dibatalkan oleh OPD', 'data' => $ticket]);
        }

        if ($user->role === 'admin') return response()->json(['message' => 'Akses ditolak.'], 403);
        if ($user->role === 'staff' && $ticket->assigned_staff_id !== $user->id) return response()->json(['message' => 'Akses ditolak.'], 403);
        
        $ticket->status = $request->status;
        if ($request->status === 'completed') $ticket->completed_at = now();

        $ticket->save();
        return response()->json(['message' => 'Status tiket berhasil diperbarui', 'data' => $ticket]);
    }

    public function claimTicket(Request $request, $id)
    {
        $user = Auth::user();
        if ($user->attendance_status !== 'Masuk') return response()->json(['message' => 'Gagal mengambil tugas. Status kehadiran Anda saat ini tidak "Masuk" (Cuti/Izin/Sakit).'], 403);

        $ticket = Ticket::with('service')->find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        if (!is_null($ticket->assigned_staff_id)) return response()->json(['message' => 'Tiket sudah diambil staf lain.'], 403);
        
        $serviceName = strtolower($ticket->service->name ?? '');
        $isScheduleBased = str_contains($serviceName, 'zoom') || str_contains($serviceName, 'command');

        if (!$isScheduleBased) {
            $request->validate(['estimated_days' => 'required|integer|min:1']);
            $ticket->estimated_days = $request->estimated_days;
            $ticket->due_date = now()->addDays($request->estimated_days);
        }

        $ticket->assigned_staff_id = $user->id;
        $ticket->assigned_at = now();
        $ticket->status = 'assigned';
        $ticket->save();
        
        return response()->json(['message' => 'Tiket berhasil diambil', 'data' => $ticket->load(['service', 'staff', 'requester'])]);
    }
    
    public function approveOrRejectTicket(Request $request, $id)
    {
        $request->validate(['action' => 'required|in:approve,reject']);
        $user = Auth::user();
        $ticket = Ticket::with('service')->find($id);
        
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        $serviceName = strtolower($ticket->service->name ?? '');
        if (!str_contains($serviceName, 'zoom') && !str_contains($serviceName, 'command')) return response()->json(['message' => 'Aksi ini hanya untuk layanan Zoom atau Command Center.'], 403);
        if (!is_null($ticket->assigned_staff_id)) return response()->json(['message' => 'Tiket sudah ditangani oleh staf lain.'], 403);

        if ($request->action === 'approve') {
            $ticket->assigned_staff_id = $user->id;
            $ticket->status = 'approved_admin';
            $ticket->save();
            return response()->json(['message' => 'Layanan berhasil Diterima', 'data' => $ticket->load(['service', 'staff', 'requester'])]);
        } else {
            $ticket->status = 'rejected';
            $ticket->save();
            return response()->json(['message' => 'Layanan Ditolak', 'data' => $ticket->load(['service', 'staff', 'requester'])]);
        }
    }

    public function previewPdf($id)
    {
        $ticket = Ticket::with(['service', 'staff', 'requester'])->find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        // TAMBAHKAN "Ticket #" DI SINI
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
        
        // TAMBAHKAN "Ticket #" DI SINI
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