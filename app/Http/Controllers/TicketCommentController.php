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

        public function store(Request $request, $ticket_id)
    {
        $request->validate([
            'message' => 'nullable|string|max:2000',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,pdf,docx,xlsx|max:10240',
        ]);

        $ticket = Ticket::find($ticket_id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);

        if (auth()->user()->role === 'opd' && $ticket->user_id !== auth()->id()) {
            return response()->json(['message' => 'Akses ditolak. Bukan tiket Anda.'], 403);
        }

        // === VALIDASI BARU: DISKUSI DIKUNCI SEBELUM DIAMBIL STAFF ===
        if (is_null($ticket->assigned_staff_id)) {
            return response()->json(['message' => 'Ruang diskusi belum bisa digunakan. Tiket belum ditangani oleh Staff.'], 403);
        }
        // =========================================================

        if (!$request->message && !$request->hasFile('file')) {
            return response()->json(['message' => 'Pesan atau lampiran file harus diisi.'], 422);
        }

        try {
            $filePath = null;
            if ($request->hasFile('file')) {
                $filePath = $request->file('file')->store('comments', 'public');
            }

            $comment = TicketComment::create([
                'ticket_id' => $ticket_id,
                'user_id'   => auth()->id(),
                'message'   => $request->message,
                'file_path' => $filePath,
            ]);

            $nomorTiket = $ticket->ticket_number ?? 'Tiket #' . $ticket_id;
            $nomorTiket = str_replace('Legacy-ID-', 'Tiket #', $nomorTiket);
            $role = strtoupper($comment->user->role ?? 'User');
            $lampiranInfo = $filePath ? "\n📎 *Lampiran:* File terunggah" : "";

            $text = "📩"
                  . "━━━━━━━━━━━━━━━━━━━\n"
                  . "Ticket : *{$nomorTiket}*\n"
                  . "Dari   : *{$comment->user->name}* ({$role})\n"
                  . "Pesan  : " . ($comment->message ?? '-')
                  . "{$lampiranInfo}\n"
                  . "━━━━━━━━━━━━━━━━━━━";

            \App\Jobs\SendTelegramJob::dispatch($text);

            return response()->json(
                $comment->load('user:id,name,role'),
                201
            );

        } catch (\Exception $e) {
            if (isset($filePath)) Storage::disk('public')->delete($filePath);
            return response()->json([
                'message' => 'Gagal menyimpan komentar',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}