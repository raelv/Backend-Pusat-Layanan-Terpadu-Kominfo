<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Ticket;
use App\Models\TicketReminderLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class SendDeadlineReminders extends Command
{
    protected $signature = 'reminders:send-deadline';
    protected $description = 'Cek tiket mendekati deadline dan kirim notif langsung ke Telegram Group Staff';

    public function handle()
    {
        $now = Carbon::now('Asia/Makassar');
        
        // ✅ PAKAI YANG SUDAH ADA DI .ENV
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID');
        

        // Skip jika config bot belum diisi
        if (!$botToken || !$chatId) {
            $this->warn('TELEGRAM_BOT_TOKEN atau TELEGRAM_CHAT_ID belum diatur di file .env');
            return 0;
        }

        // Cari tiket yang belum selesai, punya staff, dan punya due_date
        $tickets = Ticket::with('service', 'staff')
            ->whereNotNull('assigned_staff_id')
            ->whereNotNull('due_date')
            ->whereNotIn('status', ['completed', 'rejected', 'cancelled'])
            ->get();

        $count = 0;

        foreach ($tickets as $ticket) {
            $dueDate = Carbon::parse($ticket->due_date, 'Asia/Makassar')->startOfDay();
            $diffInDays = $now->diffInDays($dueDate, false); 

            $level = null;
            $emoji = null;

            // Tentukan level reminder
            if ($diffInDays == 3) {
                $level = 'H-3';
                $emoji = "⏳";
            } elseif ($diffInDays == 1) {
                $level = 'H-1';
                $emoji = "🔥";
            } elseif ($diffInDays == 0) {
                $level = 'H-0';
                $emoji = "🚨";
            } elseif ($diffInDays < 0) {
                continue; // Lewati jika sudah lewat
            }

            if ($level) {
                // CEK ANTI-SPAM: Sudah pernah kirim level ini belum?
                $alreadySent = TicketReminderLog::where('ticket_id', $ticket->id)
                    ->where('reminder_level', $level)
                    ->exists();

                if (!$alreadySent) {
                    $staffName = $ticket->staff->name ?? 'Staff';
                    $serviceName = $ticket->service->name ?? 'Layanan';
                    $formattedDate = $dueDate->format('d M Y');

                    // Format pesan Telegram (pakai Markdown)
                    $text = "{$emoji} *REMINDER DEADLINE*\n".
                            "└─ Ticket: #{$ticket->ticket_number}\n".
                            "└─ Layanan: {$serviceName}\n".
                            "└─ Tenggat: *{$formattedDate}*\n".
                            "└─ Petugas: *{$staffName}*\n".
                            "_Segera selesaikan atau lakukan update progres._";

                    // KIRIM KE TELEGRAM VIA HTTP CLIENT
                    $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                        'chat_id' => $chatId,
                        'text' => $text,
                        'parse_mode' => 'Markdown'
                    ]);

                    // Jika berhasil kirim ke Telegram, baru simpan log ke DB
                    if ($response->successful()) {
                        TicketReminderLog::create([
                            'ticket_id' => $ticket->id,
                            'staff_id' => $ticket->assigned_staff_id,
                            'reminder_level' => $level,
                            'message' => $text,
                            'sent_at' => $now,
                        ]);

                        $this->info("Telegram terkirim: {$level} untuk Ticket #{$ticket->ticket_number}");
                        $count++;
                    } else {
                        $this->error("Gagal kirim ke Telegram: " . $response->body());
                    }
                }
            }
        }

        $this->info("Proses selesai. Total notif Telegram baru: {$count}");
    }
}