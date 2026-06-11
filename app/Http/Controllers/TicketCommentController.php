<?php

namespace App\Http\Controllers;

use App\Models\TicketComment;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TicketCommentController extends Controller
{
    public function index($ticket_id)
    {
        $ticket = Ticket::find($ticket_id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);

        if (auth()->user()->role === 'opd' && $ticket->user_id !== auth()->id()) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $comments = TicketComment::where('ticket_id', $ticket_id)
            ->with('user:id,name,role')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($comments);
    }

        public function store(Request $request)
    {
        $user = Auth::user();

        // ==========================================
        // 1. VALIDASI JAM OPERASIONAL UNTUK OPD
        // ==========================================
        if ($user->role === 'opd') {
            $now = \Carbon\Carbon::now();
            $startTime = \Carbon\Carbon::createFromTime(7, 30, 0); // 07:30
            $endTime = \Carbon\Carbon::createFromTime(22, 0, 0);   // 22:00

            // Cek apakah waktu sekarang di luar jam 07:30 - 22:00
            if ($now->lt($startTime) || $now->gt($endTime)) {
                return response()->json([
                    'message' => 'Pengajuan layanan sedang ditutup. Jam operasional layanan adalah 07:30 - 22:00.'
                ], 403);
            }
        }

        // Validasi Form biasa
        $request->validate([
            'service_id' => 'required|exists:services,id',
            'form_data' => 'required', 
            'schedule_start' => 'nullable|date',
        ]);
        
        $formData = $request->form_data;
        if (is_string($formData)) $formData = json_decode($formData, true);

        // Ambil data layanan
        $service = Service::find($request->service_id);
        $isScheduleBased = str_contains(strtolower($service->name), 'zoom') || str_contains(strtolower($service->name), 'command');

        // ==========================================
        // 2. VALIDASI JADWAL BOOKING (ZOOM / COMMAND CENTER)
        // ==========================================
        if ($isScheduleBased && $request->has('schedule_start')) {
            $scheduleTime = \Carbon\Carbon::parse($request->schedule_start);
            $startTime = \Carbon\Carbon::createFromTime(7, 30, 0); // 07:30
            $endTime = \Carbon\Carbon::createFromTime(22, 0, 0);   // 22:00

            // Cek apakah jam yang dipilih OPD di luar jam operasional
            if ($scheduleTime->lt($startTime) || $scheduleTime->gt($endTime)) {
                return response()->json([
                    'message' => 'Jam pelaksanaan yang dipilih di luar jam operasional (07:30 - 22:00). Silakan pilih jam lain.'
                ], 422);
            }
        }

        // LOGIKA SLA HYBRID SAAT TIKET DIBUAT
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
        
        // SIMPAN NOMOR TIKET GLOBAL
        $ticket->ticket_number = $ticket->id;
        $ticket->save();
        
        return response()->json(['message' => 'Tiket berhasil dibuat', 'data' => $ticket->load(['service', 'requester'])], 201);
    }
}