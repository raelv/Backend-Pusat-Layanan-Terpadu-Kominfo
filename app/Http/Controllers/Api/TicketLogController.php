<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;

class TicketLogController extends Controller
{
    /**
     * GET /api/tickets/{ticket}/logs
     * Timeline log aktivitas (audit trail) sebuah tiket.
     */
    public function index(Ticket $ticket): JsonResponse
    {
        $user = auth()->user();

        if (!in_array($user->role, ['admin', 'pimpinan']) &&
            $ticket->user_id !== $user->id &&
            $ticket->assigned_staff_id !== $user->id) {
            return response()->json([
                'message' => 'Akses ditolak. Anda tidak berhak melihat riwayat tiket ini.'
            ], 403);
        }

        $logs = $ticket->logs()
            ->with('actor:id,name,role')
            ->reorder()
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc') // tie-breaker jika timestamp sama persis
            ->get()
            ->map(function ($log) {
                return [
                    'id'          => $log->id,
                    'time'        => $log->created_at
                                        ? $log->created_at->format('d M Y, H:i')
                                        : '-',
                    'actor'       => $log->actor_name, // "Nama (ROLE)" atau "Sistem"
                    'action'      => $log->action,
                    'description' => $log->description,
                ];
            });

        return response()->json([
            'data' => $logs
        ]);
    }
}