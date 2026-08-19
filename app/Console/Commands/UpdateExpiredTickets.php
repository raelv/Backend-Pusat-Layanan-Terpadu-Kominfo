<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Ticket;
use Carbon\Carbon;

class UpdateExpiredTickets extends Command
{
    protected $signature = 'tickets:check-expired';
    protected $description = 'Ubah tiket yang sudah melewati jadwal menjadi expired';

    public function handle()
    {
        // Cari tiket Zoom/Command Center yang jadwalnya sudah lewat tapi statusnya masih pending/queued
        $tickets = Ticket::whereHas('service', function($q) {
                $q->whereIn('category', ['zoom', 'command_center']);
            })
            ->whereIn('status', ['pending', 'queued'])
            ->whereNotNull('schedule_start')
            ->where('schedule_start', '<', Carbon::now('Asia/Makassar'))
            ->get();

        foreach ($tickets as $ticket) {
            $ticket->update(['status' => 'expired']);
            $this->info("Ticket #{$ticket->ticket_number} diubah menjadi expired.");
        }

        $this->info("Proses selesai. Total {$tickets->count()} tiket diupdate.");
    }
}