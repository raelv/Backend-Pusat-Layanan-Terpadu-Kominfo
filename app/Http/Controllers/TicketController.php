<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TicketController extends Controller
{
    // 1. LIST SEMUA TIKET
    public function index()
    {
        $user = Auth::user();

        if ($user->role === 'admin') {
            return Ticket::with(['service', 'staff', 'requester', 'comments.user'])->get();
        }

        if ($user->role === 'staff') {
            return Ticket::where('assigned_staff_id', $user->id)
                ->orWhere(function($query) {
                    $query->whereNull('assigned_staff_id')
                          ->whereIn('status', ['pending', 'queued']);
                })
                ->with(['service', 'staff', 'requester', 'comments.user'])
                ->get();
        }

        return Ticket::where('user_id', $user->id)
            ->with(['service', 'staff', 'requester', 'comments.user'])
            ->get();
    }

    // 2. DETAIL TIKET
    public function show($id)
    {
        $ticket = Ticket::with(['service', 'staff', 'requester', 'comments.user'])->find($id);

        if (!$ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        return response()->json($ticket);
    }

    // 3. BUAT TIKET BARU
    public function store(Request $request)
    {
        $request->validate([
            'service_id'     => 'required|exists:services,id',
            'form_data'      => 'required', 
            'schedule_start' => 'nullable|date',
        ]);

        $user = Auth::user();

        $formData = $request->form_data;
        if (is_string($formData)) {
            $formData = json_decode($formData, true);
        }

        $ticket = Ticket::create([
            'service_id'     => $request->service_id,
            'user_id'        => $user->id, 
            'form_data'      => $formData,
            'status'         => 'pending', 
            'schedule_start' => $request->schedule_start,
        ]);

        return response()->json([
            'message' => 'Tiket berhasil dibuat',
            'data'    => $ticket->load(['service', 'requester'])
        ], 201);
    }

    // 4. UPDATE STATUS TIKET
    public function updateStatus(Request $request, $id)
    {
        // DIPERBAIKI: Validasi diizinkan sesuai enum di database migration
        $request->validate([
            'status' => 'required|in:pending,queued,approved_admin,assigned,in_progress,completed,rejected,cancelled',
        ]);

        $user = Auth::user();
        $ticket = Ticket::find($id);

        if (!$ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        if ($user->role === 'admin') {
            return response()->json(['message' => 'Akses ditolak. Admin hanya bisa monitoring tiket.'], 403);
        }

        if ($user->role === 'staff' && $ticket->assigned_staff_id !== $user->id) {
            return response()->json(['message' => 'Akses ditolak. Ini bukan tiket tugas Anda.'], 403);
        }

        $ticket->status = $request->status;
        $ticket->save();

        return response()->json([
            'message' => 'Status tiket berhasil diperbarui',
            'data'    => $ticket
        ]);
    }

    // 5. CLAIM TIKET (TOMBOL "AMBIL TUGAS") <- FUNGSI BARU!
    public function claimTicket(Request $request, $id)
    {
        $user = Auth::user();
        $ticket = Ticket::find($id);

        if (!$ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        // Cek apakah tiket sudah di-claim staf lain
        if (!is_null($ticket->assigned_staff_id)) {
            return response()->json(['message' => 'Tiket sudah diambil staf lain.'], 403);
        }

        // Otomatis set ID staf yang login dan ubah status
        $ticket->assigned_staff_id = $user->id;
        $ticket->status = 'assigned'; // Status sesuai database
        $ticket->save();

        return response()->json([
            'message' => 'Tiket berhasil diambil',
            'data'    => $ticket->load(['service', 'staff', 'requester'])
        ]);
    }

    // 6. TAMBAH KOMENTAR PADA TIKET
    public function addComment(Request $request, $id)
    {
        $request->validate([
            'comment' => 'required|string',
        ]);

        $ticket = Ticket::find($id);
        if (!$ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        $user = Auth::user();

        if ($user->role === 'admin') {
            return response()->json(['message' => 'Admin hanya memonitoring, tidak dapat memberi komentar.'], 403);
        }

        $comment = $ticket->comments()->create([
            'user_id' => $user->id,
            'comment' => $request->comment,
        ]);

        return response()->json([
            'message' => 'Komentar berhasil ditambahkan',
            'data'    => $comment->load('user')
        ], 201);
    }
}