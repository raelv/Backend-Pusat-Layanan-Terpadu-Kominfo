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
        $logs = $ticket->logs()->orderBy('created_at', 'asc')->get()->map(function ($log) {
            return [
                "id"          => $log->id,
                "time"        => $log->created_at->format('d M Y, H:i'),
                "actor"       => $log->actor_name, // Akan otomatis "Nama (Role)" atau "Sistem"
                "action"      => $log->action,
                "description" => $log->description,
            ];
        });

        return response()->json([
            "data" => $logs
        ]);
    }
}