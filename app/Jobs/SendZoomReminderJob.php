<?php

namespace App\Jobs;

use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendZoomReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $ticketId;
    protected $type; // 'before_start' atau 'after_end'

    public function __construct($ticketId, $type)
    {
        $this->ticketId = $ticketId;
        $this->type = $type;
    }

    public function handle()
    {
        $ticket = Ticket::with(['service', 'requester', 'zoomLink', 'staff'])->find($this->ticketId);

        if (!$ticket) {
            return;
        }

        // Hanya untuk layanan Zoom
        if (strtolower($ticket->service->category ?? '') !== 'zoom') {
            return;
        }

        // Jika tiket sudah selesai/ditolak/batal, jangan kirim notifikasi
        if (in_array($ticket->status, ['completed', 'rejected', 'cancelled'])) {
            return;
        }

        $opdChatId = $ticket->requester->telegram_chat_id ?? null;
        if (!$opdChatId) {
            return;
        }

        $tanggal = Carbon::parse($ticket->schedule_start)->format('d F Y');
        $jamMulai = Carbon::parse($ticket->schedule_start)->format('H.i');
        $jamSelesai = Carbon::parse($ticket->schedule_end)->format('H.i');

        if ($this->type === 'before_start') {
            $zoomLink = $ticket->zoomLink ? $ticket->zoomLink->link : null;
            $staffName = $ticket->staff ? $ticket->staff->name : 'Belum Ditugaskan';

            if ($zoomLink) {
                // ✅ Link Zoom tersedia
                $message = "⏰ *PENGINGAT LAYANAN ZOOM*\n━━━━━━━━━━━━━━━━━━━\n" .
                    "Ticket : #{$ticket->ticket_number}\n" .
                    "Layanan: {$ticket->service->name}\n" .
                    "Petugas: *{$staffName}*\n" .
                    "━━━━━━━━━━━━━━━━━━━\n" .
                    "📅 Tanggal: {$tanggal}\n" .
                    "🕐 Waktu: {$jamMulai} - {$jamSelesai} WITA\n" .
                    "🔗 Link Zoom: {$zoomLink}\n" .
                    "━━━━━━━━━━━━━━━━━━━\n" .
                    "_Layanan akan segera dimulai. Silakan persiapkan diri Anda._";
            } else {
                // ✅ Link Zoom belum tersedia
                $message = "⏰ *PENGINGAT LAYANAN ZOOM*\n━━━━━━━━━━━━━━━━━━━\n" .
                    "Ticket : #{$ticket->ticket_number}\n" .
                    "Layanan: {$ticket->service->name}\n" .
                    "Petugas: *{$staffName}*\n" .
                    "━━━━━━━━━━━━━━━━━━━\n" .
                    "📅 Tanggal: {$tanggal}\n" .
                    "🕐 Waktu: {$jamMulai} - {$jamSelesai} WITA\n" .
                    "⚠️ Link Zoom belum disediakan.\n" .
                    "━━━━━━━━━━━━━━━━━━━\n" .
                    "_Silakan menunggu atau menghubungi Staff._";
            }

            SendTelegramJob::dispatch($message, $opdChatId);

        } elseif ($this->type === 'after_end') {
            // ✅ Jadwal sudah selesai
            $message = "🏁 *JADWAL ZOOM BERAKHIR*\n━━━━━━━━━━━━━━━━━━━\n" .
                "Ticket : #{$ticket->ticket_number}\n" .
                "Layanan: {$ticket->service->name}\n" .
                "━━━━━━━━━━━━━━━━━━━\n" .
                "📅 Tanggal: {$tanggal}\n" .
                "🕐 Waktu: {$jamMulai} - {$jamSelesai} WITA\n" .
                "━━━━━━━━━━━━━━━━━━━\n" .
                "_Jadwal layanan Zoom telah berakhir.\n" .
                "Silakan tandai tugas sebagai selesai apabila layanan sudah selesai._";

            SendTelegramJob::dispatch($message, $opdChatId);
        }
    }
}