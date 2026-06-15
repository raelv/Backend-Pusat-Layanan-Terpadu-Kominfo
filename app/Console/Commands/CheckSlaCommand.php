<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Ticket;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CheckSlaCommand extends Command
{
    protected $signature = 'tickets:check-sla';
    protected $description = 'Cek SLA terlambat, kirim reminder, dan auto-expired tiket Zoom/Command Center';

    public function handle()
    {
        $this->info('Memulai pengecekan SLA...');

        // 1. AUTO EXPIRED (Khusus Zoom / Command Center yang jadwalnya sudah lewat)
        $services = Service::where('name', 'LIKE', '%Zoom%')
                          ->orWhere('name', 'LIKE', '%Command Center%')
                          ->pluck('id');

        $expiredTickets = Ticket::whereIn('service_id', $services)
            ->whereNotNull('schedule_start')
            ->where('schedule_start', '<', Carbon::now())
            ->whereIn('status', ['pending', 'queued', 'approved_admin'])
            ->get();

        foreach ($expiredTickets as $ticket) {
            $ticket->update(['status' => 'expired']);
            $nomorTiket = 'Ticket #' . $ticket->ticket_number;
            $this->warn("{$nomorTiket} EXPIRED (Jadwal sudah lewat).");
        }

        // 2. REMINDER TERLAMBAT (Kirim peringatan kalau melewati due_date tapi belum selesai)
        $overdueTickets = Ticket::whereNotNull('due_date')
            ->where('due_date', '<', Carbon::now())
            ->whereNotIn('status', ['completed', 'rejected', 'cancelled', 'expired'])
            ->get();

        foreach ($overdueTickets as $ticket) {
            $nomorTiket = 'Ticket #' . $ticket->ticket_number;
            
            $text = "🚨 *PERINGATAN SLA TERLAMBAT*\n"
                  . "━━━━━━━━━━━━━━━━━━━\n"
                  . "Ticket : *{$nomorTiket}*\n"
                  . "Status : *{$ticket->status}*\n"
                  . "Batas  : " . $ticket->due_date->format('d M Y') . "\n"
                  . "━━━━━━━━━━━━━━━━━━━";
            
            \App\Jobs\SendTelegramJob::dispatch($text);
            $this->warn("Reminder terkirim untuk {$nomorTiket}");
        }

        $this->info('Pengecekan SLA selesai.');
        return 0;
    }
}