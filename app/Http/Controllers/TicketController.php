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

        if ($user->role === 'admin') {
            // Admin lihat semua
        } elseif ($user->role === 'staff') {
            $query->where('assigned_staff_id', $user->id)
                  ->orWhere(function($q) {
                      $q->whereNull('assigned_staff_id')
                        ->whereIn('status', ['pending', 'queued']);
                  });
        } else {
            // OPD boleh lihat tiket miliknya sendiri, ATAU tiket yang sedang di-antrikan (Waiting List)
            $query->where(function($q) use ($user) {
                $q->where('user_id', $user->id) 
                  ->orWhereIn('status', ['pending', 'queued']);
            });
        }

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
        $request->validate([
            'service_id' => 'required|exists:services,id',
            'form_data' => 'required', 
            'schedule_start' => 'nullable|date',
        ]);
        
        $formData = $request->form_data;
        if (is_string($formData)) $formData = json_decode($formData, true);

        $user = Auth::user(); 

        $jumlahTiketSebelumnya = Ticket::where('user_id', $user->id)->count();
        $nomorUrut = $jumlahTiketSebelumnya + 1;
        $ticketNumber = 'Ticket #' . $nomorUrut . ' (' . $user->name . ')';

        // --- LOGIKA SLA HYBRID SAAT TIKET DIBUAT ---
        $service = Service::find($request->service_id);
        $dueDate = null;
        
        // Cek apakah layanan ini Zoom atau Command Center
        $isScheduleBased = str_contains(strtolower($service->name), 'zoom') || str_contains(strtolower($service->name), 'command');

        if ($isScheduleBased && $service->sla_days > 0) {
            // SLA TETAP: Langsung hitung due_date saat tiket dibuat
            $dueDate = now()->addDays($service->sla_days);
        }
        // Jika Layanan IT/Web, due_date dibiarkan NULL (nanti diisi saat Staff claim)
        // -----------------------------------------

        $ticket = Ticket::create([
            'ticket_number' => $ticketNumber, 
            'service_id' => $request->service_id,
            'user_id' => $user->id, 
            'form_data' => $formData,
            'status' => 'pending', 
            'schedule_start' => $request->schedule_start,
            'due_date' => $dueDate, 
        ]);
        
        return response()->json(['message' => 'Tiket berhasil dibuat', 'data' => $ticket->load(['service', 'requester'])], 201);
    }

    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $ticket = Ticket::find($id);

        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        
        if ($ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Hanya pemohon yang bisa mengubah permohonan ini.'], 403);
        }

        if (!in_array($ticket->status, ['pending', 'queued'])) {
            return response()->json(['message' => 'Tiket sudah diproses. Perubahan hanya bisa dilakukan melalui ruang diskusi.'], 403);
        }

        $request->validate([
            'form_data' => 'required', 
            'schedule_start' => 'nullable|date',
        ]);

        $formData = $request->form_data;
        if (is_string($formData)) $formData = json_decode($formData, true);

        $ticket->form_data = $formData;
        if ($request->has('schedule_start')) {
            $ticket->schedule_start = $request->schedule_start;
        }
        $ticket->save();

        return response()->json(['message' => 'Permohonan berhasil diperbarui', 'data' => $ticket]);
    }

    // ==========================================
    // FITUR 3: PEMBATALAN PERMOHONAN OLEH OPD
    // ==========================================
     public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:pending,queued,approved_admin,assigned,in_progress,completed,rejected,cancelled,expired']);
        $user = Auth::user();
        $ticket = Ticket::find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        
        if ($request->status === 'cancelled' && $user->role === 'opd') {
            if ($ticket->user_id !== $user->id) {
                return response()->json(['message' => 'Akses ditolak.'], 403);
            }
            if (!in_array($ticket->status, ['pending', 'queued'])) {
                return response()->json(['message' => 'Gagal membatalkan. Tiket yang sedang/d sudah diproses tidak bisa dibatalkan langsung.'], 403);
            }
            $ticket->status = 'cancelled';
            $ticket->save();
            return response()->json(['message' => 'Permohonan berhasil dibatalkan oleh OPD', 'data' => $ticket]);
        }

        if ($user->role === 'admin') return response()->json(['message' => 'Akses ditolak.'], 403);
        if ($user->role === 'staff' && $ticket->assigned_staff_id !== $user->id) return response()->json(['message' => 'Akses ditolak.'], 403);
        
        $ticket->status = $request->status;

        // LOGIKA BARU: Catat waktu selesai jika statusnya completed
        if ($request->status === 'completed') {
            $ticket->completed_at = now();
        }

        $ticket->save();
        return response()->json(['message' => 'Status tiket berhasil diperbarui', 'data' => $ticket]);
    }

        public function claimTicket(Request $request, $id)
    {
        $user = Auth::user();

        // === VALIDASI BARU: CEK KEHADIRAN STAFF ===
        if ($user->attendance_status !== 'Masuk') {
            return response()->json(['message' => 'Gagal mengambil tugas. Status kehadiran Anda saat ini tidak "Masuk" (Cuti/Izin/Sakit).'], 403);
        }
        // ==========================================

        $ticket = Ticket::with('service')->find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        if (!is_null($ticket->assigned_staff_id)) return response()->json(['message' => 'Tiket sudah diambil staf lain.'], 403);
        
        $serviceName = strtolower($ticket->service->name ?? '');
        $isScheduleBased = str_contains($serviceName, 'zoom') || str_contains($serviceName, 'command');

        if ($isScheduleBased) {
            // Zoom/CC tidak perlu estimasi
        } else {
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
        $request->validate([
            'action' => 'required|in:approve,reject'
        ]);

        $user = Auth::user();
        $ticket = Ticket::with('service')->find($id);
        
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);

        $serviceName = strtolower($ticket->service->name ?? '');
        if (!str_contains($serviceName, 'zoom') && !str_contains($serviceName, 'command')) {
            return response()->json(['message' => 'Aksi ini hanya untuk layanan Zoom atau Command Center.'], 403);
        }

        if (!is_null($ticket->assigned_staff_id)) {
            return response()->json(['message' => 'Tiket sudah ditangani oleh staf lain.'], 403);
        }

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
        return Pdf::loadView('pdf.bukti-layanan', compact('ticket'))->setPaper('A4', 'portrait')->stream('Bukti_Layanan_'.str_replace(' ', '_', $ticket->ticket_number).'.pdf');
    }

    public function downloadPdf($id)
    {
        $ticket = Ticket::with(['service', 'staff', 'requester'])->find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        return Pdf::loadView('pdf.bukti-layanan', compact('ticket'))->setPaper('A4', 'portrait')->download('Bukti_Layanan_'.str_replace(' ', '_', $ticket->ticket_number).'.pdf');
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
        $section->addText('Nomor: ' . $ticket->ticket_number, ['size' => 11, 'alignment' => 'center']);
        $section->addTextBreak(1);
        $section->addText('Hari / Tanggal    : ' . \Carbon\Carbon::parse($ticket->created_at)->translatedFormat('l, d F Y'));
        $section->addText('Jenis Layanan      : ' . ($ticket->service->name ?? 'N/A'));
        $section->addText('Status                : ' . strtoupper($ticket->status));
        $section->addText('Pelaksana Staf    : ' . ($ticket->staff->name ?? 'Belum Ditugaskan'));
        
        $fileName = 'Bukti_Layanan_' . str_replace(' ', '_', $ticket->ticket_number) . '.docx';
        $tempFilePath = storage_path($fileName);
        $phpWord->save($tempFilePath, 'Word2007');
        return response()->download($tempFilePath)->deleteFileAfterSend(true);
    }
}