<?php

namespace App\Http\Controllers;

use App\Models\TicketComment;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Jobs\SendTelegramJob;

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

        $ticket = Ticket::with(['service', 'requester', 'staff'])->find($ticket_id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);

        if (auth()->user()->role === 'opd' && $ticket->user_id !== auth()->id()) {
            return response()->json(['message' => 'Akses ditolak. Bukan tiket Anda.'], 403);
        }

        if (is_null($ticket->assigned_staff_id)) {
            return response()->json(['message' => 'Ruang diskusi belum bisa digunakan. Tiket belum ditangani oleh Staff.'], 403);
        }

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

            // ✅ FORMAT PESAN
            $nomorTiket = 'Ticket ' . ($ticket->ticket_number ?? $ticket_id);
            $role = strtoupper($comment->user->role ?? 'User');
            $namaLayanan = $ticket->service->name ?? 'Tidak diketahui';
            $namaPemohon = $ticket->requester->name ?? 'Tidak diketahui';
            $lampiranInfo = $filePath ? "\nLampiran: File terunggah" : "";
            
            $pesanUser = $comment->message ?? '-';
            if (strlen($pesanUser) > 100) {
                $pesanUser = substr($pesanUser, 0, 100) . '...';
            }

            $text = "Komentar Baru\n"
                  . "━━━━━━━━━━━━━━━━━━━\n"
                  . "Ticket   : {$nomorTiket}\n"
                  . "Layanan  : {$namaLayanan}\n"
                  . "Pemohon  : {$namaPemohon}\n"
                  . "Dari     : {$comment->user->name} ({$role})\n"
                  . "Pesan    : {$pesanUser}"
                  . "{$lampiranInfo}\n"
                  . "━━━━━━━━━━━━━━━━━━━";
            
            // ✅ 1. SELALU KIRIM KE GROUP STAFF UNTUK MONITORING UMUM
            SendTelegramJob::dispatch($text);

            // ✅ 2. KIRIM DM KE LAWAN BICARA (PRIVATE CHAT)
            $actorRole = $comment->user->role;
            
            if ($actorRole === 'opd' && $ticket->staff && $ticket->staff->telegram_chat_id) {
                // Jika yang komen OPD, kirim DM ke Staff yang handle tiket
                SendTelegramJob::dispatch($text, $ticket->staff->telegram_chat_id);
            } elseif ($actorRole === 'staff' && $ticket->requester && $ticket->requester->telegram_chat_id) {
                // Jika yang komen Staff, kirim DM ke OPD pemohon tiket
                SendTelegramJob::dispatch($text, $ticket->requester->telegram_chat_id);
            }

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