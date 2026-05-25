<?php

namespace App\Http\Controllers;

use App\Models\TicketComment;
use Illuminate\Http\Request;

class TicketCommentController extends Controller
{
    public function index($ticket_id)
    {
        $comments = TicketComment::where('ticket_id', $ticket_id)
            ->with('user:id,name,role')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($comments);
    }

    public function store(Request $request, $ticket_id)
    {
        $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        try {
            // 1. Simpan komentar
            $comment = TicketComment::create([
                'ticket_id' => $ticket_id,
                'user_id'   => auth()->id(),
                'message'   => $request->message,
            ]);

            // 2. Kirim notif Telegram pakai JOB & QUEUE
            $text = "💬 Komentar baru di Ticket #{$ticket_id}\n"
                  . "Dari: {$comment->user->name}\n"
                  . "Pesan: " . substr($comment->message, 0, 100);

            // Dispatch ke Redis Queue (Menggunakan Job yang sudah kamu buat)
            \App\Jobs\SendTelegramJob::dispatch($text, $comment->user_id);

            // 3. Return sukses
            return response()->json(
                $comment->load('user:id,name,role'),
                201
            );

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal menyimpan komentar',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}