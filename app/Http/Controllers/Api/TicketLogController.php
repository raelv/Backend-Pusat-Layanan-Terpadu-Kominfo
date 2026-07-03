<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;

class TicketLogController extends Controller
{
    /**
     * GET /api/tickets/{ticket}/logs
     */
    public function index(Ticket $ticket): JsonResponse
    {
        $user = auth()->user();
        
        // ✅ FIX SECURITY: Hanya Admin, Staff terkait, atau Pemohon yang boleh akses
        if ($user->role !== 'admin' && 
            $ticket->user_id !== $user->id && 
            $ticket->assigned_staff_id !== $user->id) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak berhak melihat riwayat tiket ini.'], 403);
        }

        $logs = $ticket->logs()->orderBy('created_at', 'asc')->get()->map(function ($log) {
            return [
                "id"          => $log->id,
                "time"        => $log->created_at->format('d M Y, H:i'),
                "actor"       => $log->actor_name,
                "action"      => $log->action,
                "description" => $log->description,
            ];
        });

        return response()->json([
            "data" => $logs
        ]);
    }
}