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
    protected $description = 'Cek SLA terlambat, kirim reminder sekali, dan auto-expired tiket Zoom/Command Center';

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
            // ✅ AUTO-RELEASE: Lepaskan kunci link Zoom jika expired
            if ($ticket->zoom_link_id) {
                \App\Models\ZoomLink::where('id', $ticket->zoom_link_id)->update([
                    'status' => 'available', 
                    'used_by_ticket_id' => null
                ]);
            }

            $ticket->update(['status' => 'expired', 'zoom_link_id' => null]);
            $nomorTiket = 'Ticket #' . $ticket->ticket_number;
            $this->warn("{$nomorTiket} EXPIRED (Jadwal sudah lewat).");
        }

        // 2. REMINDER TERLAMBAT (Kirim peringatan kalau melewati due_date tapi belum selesai)
        // ✅ FIX SPAM: Tambahkan where('is_sla_notified', false)
        $overdueTickets = Ticket::whereNotNull('due_date')
            ->where('due_date', '<', Carbon::now())
            ->whereNotIn('status', ['completed', 'rejected', 'cancelled', 'expired'])
            ->where('is_sla_notified', false) // ✅ Hanya ambil yang BELUM pernah dikirim notif
            ->get();

        foreach ($overdueTickets as $ticket) {
            $nomorTiket = 'Ticket #' . $ticket->ticket_number;
            
            $text = "🚨 *PERINGATAN SLA TERLAMBAT*\n"
                  . "━━━━━━━━━━━━━━━━━━━\n"
                  . "Ticket : *{$nomorTiket}*\n"
                  . "Status : *{$ticket->status}*\n"
                  . "Batas  : " . $ticket->due_date->format('d M Y') . "\n"
                  . "━━━━━━━━━━━━━━━━━━━";
            
            // ✅ FIX: Kirim ke Pimpinan, BUKAN Grup Staff
            \App\Models\User::where('role', 'pimpinan')->whereNotNull('telegram_chat_id')->each(function($pimpinan) use ($text) {
                \App\Jobs\SendTelegramJob::dispatch($text, $pimpinan->telegram_chat_id);
            });
            
            // ✅ FIX SPAM: Langsung tandai jadi TRUE setelah dikirim
            $ticket->update(['is_sla_notified' => true]);
            
            $this->warn("Reminder terkirim untuk {$nomorTiket}");
        }

        $this->info('Pengecekan SLA selesai.');
        return 0;
    }
}