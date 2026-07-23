<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\Request;

class LandingController extends Controller
{
    public function getSchedules()
    {
        $now = now();
        $nextWeek = $now->copy()->addDays(7);

        $schedules = Ticket::with(['service', 'staff'])
            ->whereHas('service', function ($q) {
                $q->whereIn('category', ['zoom', 'command_center']);
            })
            ->whereIn('status', ['assigned', 'in_progress', 'approved_admin'])
            ->whereBetween('schedule_start', [$now, $nextWeek])
            ->orderBy('schedule_start', 'asc')
            ->get()
            ->map(function ($ticket) {
                return [
                    'layanan' => $ticket->service->name,
                    'hari' => \Carbon\Carbon::parse($ticket->schedule_start)->translatedFormat('l'),
                    'tanggal' => \Carbon\Carbon::parse($ticket->schedule_start)->format('d F Y'),
                    'jam_mulai' => \Carbon\Carbon::parse($ticket->schedule_start)->format('H:i'),
                    'jam_selesai' => \Carbon\Carbon::parse($ticket->schedule_end)->format('H:i'),
                ];
            });

        $grouped = $schedules->groupBy('tanggal');

        return response()->json($grouped);
    }
}