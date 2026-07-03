<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Ticket;
use App\Models\TicketReminderLog;
use App\Models\TicketLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendDeadlineReminders extends Command
{
    protected $signature = 'reminders:send-deadline';
    protected $description = 'Kirim reminder deadline (IT), jadwal (Zoom/CC), dan notifikasi melewati jam selesai ke Telegram';

    const ACTIVE_STAFF_STATUSES = ['assigned', 'in_progress'];

    public function handle()
    {
        $now = Carbon::now('Asia/Makassar');
        
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID');

        if (!$botToken || !$chatId) {
            $this->warn('TELEGRAM_BOT_TOKEN atau TELEGRAM_CHAT_ID belum diatur');
            return 0;
        }

        $count = 0;

        // 1. REMINDER LAYANAN IT (Berdasarkan SLA/due_date)
        $this->processSlaBasedTickets($now, $botToken, $chatId, $count);
        
        // 2. REMINDER ZOOM/COMMAND CENTER (Berdasarkan schedule_start)
        $this->processScheduleBasedTickets($now, $botToken, $chatId, $count);

        // ✅ 3. NOTIFIKASI MELEWATI JAM SELESAI (Hanya sekali)
        $this->processOverdueScheduleTickets($now, $botToken, $chatId, $count);

        $this->info("Proses selesai. Total notif Telegram: {$count}");
        return 0;
    }

    private function processSlaBasedTickets(Carbon $now, string $botToken, string $chatId, int &$count): void
    {
        $tickets = Ticket::with('service', 'staff')
            ->whereNotNull('assigned_staff_id')
            ->whereNotNull('due_date')
            ->whereIn('status', self::ACTIVE_STAFF_STATUSES)
            ->whereHas('service', function ($q) {
                $q->whereNotIn('category', ['zoom', 'command_center']);
            })
            ->get();

        foreach ($tickets as $ticket) {
            $dueDate = Carbon::parse($ticket->due_date, 'Asia/Makassar')->startOfDay();
            $diffInDays = $now->diffInDays($dueDate, false);

            if ($diffInDays < 0) continue;

            $level = null;
            $emoji = null;

            if ($diffInDays == 3) { $level = 'H-3'; $emoji = "⏳"; }
            elseif ($diffInDays == 1) { $level = 'H-1'; $emoji = "🔥"; }
            elseif ($diffInDays == 0) { $level = 'H-0'; $emoji = "🚨"; }

            if (!$level) continue;
            if ($this->alreadySent($ticket->id, $level)) continue;

            $staffName = $ticket->staff->name ?? 'Staff';
            $serviceName = $ticket->service->name ?? 'Layanan';
            $formattedDate = $dueDate->format('d M Y');

            $text = "{$emoji} *REMINDER DEADLINE - LAYANAN IT*\n".
                    "━━━━━━━━━━━━━━━━━━━\n".
                    "Ticket    : #{$ticket->ticket_number}\n".
                    "Layanan  : {$serviceName}\n".
                    "Tenggat   : *{$formattedDate}*\n".
                    "Petugas  : *{$staffName}*\n".
                    "Sisa       : *{$diffInDays} hari lagi*\n".
                    "━━━━━━━━━━━━━━━━━━━\n".
                    "_Segera selesaikan atau update progres._";

            if ($this->sendTelegram($botToken, $chatId, $text)) {
                $this->logReminder($ticket, $level, $text, $now);
                
                TicketLog::create([
                    'ticket_id' => $ticket->id, 'user_id' => null,
                    'action' => 'reminder_sent', 'description' => "Sistem mengirim notifikasi reminder {$level} (Sisa {$diffInDays} hari) via Telegram.", 'created_at' => $now,
                ]);

                $this->info("[IT] {$level} terkirim: Ticket #{$ticket->ticket_number}");
                $count++;
            }
        }
    }

    private function processScheduleBasedTickets(Carbon $now, string $botToken, string $chatId, int &$count): void
    {
        $tickets = Ticket::with('service', 'staff')
            ->whereNotNull('assigned_staff_id')
            ->whereNotNull('schedule_start')
            ->whereIn('status', self::ACTIVE_STAFF_STATUSES)
            ->whereHas('service', function ($q) {
                $q->whereIn('category', ['zoom', 'command_center']);
            })
            ->whereBetween('schedule_start', [
                $now->copy()->startOfDay(),
                $now->copy()->addDay()->endOfDay()
            ])
            ->get();

        foreach ($tickets as $ticket) {
            $scheduleStart = Carbon::parse($ticket->schedule_start, 'Asia/Makassar');
            $diffInMinutes = $now->diffInMinutes($scheduleStart, false);
            $diffInHours = $now->diffInHours($scheduleStart, false);

            $level = null;
            $emoji = null;
            $timeInfo = null;

            if ($scheduleStart->isSameDay($now) && $now->hour < 9 && $diffInMinutes > 0) {
                $level = 'SCHEDULE_TODAY';
                $emoji = "📅";
                $timeInfo = "hari ini";
            }
            elseif ($diffInMinutes > 0 && $diffInHours <= 2 && $diffInHours >= 0) {
                $level = 'SCHEDULE_SOON';
                $emoji = "⏰";
                if ($diffInMinutes >= 60) {
                    $jam = floor($diffInMinutes / 60);
                    $timeInfo = "{$jam} jam lagi";
                } else {
                    $timeInfo = round($diffInMinutes) . " menit lagi";
                }
            }
            elseif ($scheduleStart->isTomorrow($now) && $now->hour >= 7 && $now->hour < 9) {
                $level = 'SCHEDULE_TOMORROW';
                $emoji = "📋";
                $timeInfo = "besok";
            }

            if (!$level) continue;
            if ($this->alreadySent($ticket->id, $level)) continue;

            $staffName = $ticket->staff->name ?? 'Staff';
            $serviceName = $ticket->service->name ?? 'Layanan';
            $categoryLabel = $ticket->service->category_label;
            $formattedSchedule = $scheduleStart->format('d M Y, H:i');
            $formattedEnd = $ticket->schedule_end ? Carbon::parse($ticket->schedule_end)->format('H:i') : '-';

            $text = "{$emoji} *REMINDER JADWAL - {$categoryLabel}*\n".
                    "━━━━━━━━━━━━━━━━━━━\n".
                    "Ticket       : #{$ticket->ticket_number}\n".
                    "Layanan     : {$serviceName}\n".
                    "Jadwal       : *{$formattedSchedule}* s/d {$formattedEnd}\n".
                    "Petugas     : *{$staffName}*\n".
                    "Dimulai     : *{$timeInfo}*\n".
                    "━━━━━━━━━━━━━━━━━━━\n".
                    "_Pastikan persiapan sudah matang._";

            if ($this->sendTelegram($botToken, $chatId, $text)) {
                $this->logReminder($ticket, $level, $text, $now);
                
                TicketLog::create([
                    'ticket_id' => $ticket->id, 'user_id' => null,
                    'action' => 'reminder_sent', 'description' => "Sistem mengirim pengingat jadwal {$categoryLabel} (Dimulai {$timeInfo}) via Telegram.", 'created_at' => $now,
                ]);

                $this->info("[{$categoryLabel}] {$level} terkirim: Ticket #{$ticket->ticket_number}");
                $count++;
            }
        }
    }

    /**
     * ✅ FITUR BARU: DETEKSI JADWAL YANG SUDAH MELEWATI JAM SELESAI
     */
    private function processOverdueScheduleTickets(Carbon $now, string $botToken, string $chatId, int &$count): void
    {
        $level = 'OVERDUE_SCHEDULE';

        $tickets = Ticket::with('service', 'staff')
            ->whereNotNull('assigned_staff_id')
            ->whereNotNull('schedule_end')
            ->whereIn('status', self::ACTIVE_STAFF_STATUSES)
            ->whereHas('service', function ($q) {
                $q->whereIn('category', ['zoom', 'command_center']);
            })
            ->where('schedule_end', '<', $now)
            // ✅ TAMBAHKAN INI: Hanya cek tiket yang jam selesainya maksimal 1 hari yang lalu (biar gak scan data jadul)
            ->where('schedule_end', '>', $now->copy()->subDay()) 
            ->get();

        foreach ($tickets as $ticket) {
            // Cek anti-spam (hanya kirim 1 kali selamanya)
            if ($this->alreadySent($ticket->id, $level)) {
                continue;
            }

            $text = "⏰ *PENGINGAT LAYANAN*\n".
                    "━━━━━━━━━━━━━━━━━━━\n".
                    "Ticket : *#{$ticket->ticket_number}*\n".
                    "Status : Telah melewati jam selesai\n".
                    "━━━━━━━━━━━━━━━━━━━\n".
                    "_Silakan menyelesaikan layanan apabila kegiatan telah selesai. Apabila kegiatan masih berlangsung, layanan dapat tetap dilanjutkan dan diselesaikan secara manual setelah benar-benar selesai._";

            // Prioritas 1: Kirim ke Chat ID Personal Staff (kalau sudah binding)
            // Prioritas 2: Kalau belum binding, kirim ke Group Staff
            $targetChatId = $ticket->staff->telegram_chat_id ?? $chatId;

            if ($this->sendTelegram($botToken, $targetChatId, $text)) {
                $this->logReminder($ticket, $level, $text, $now);
                
                TicketLog::create([
                    'ticket_id' => $ticket->id, 'user_id' => null,
                    'action' => 'overdue_notified', 'description' => 'Sistem mengirim peringatan karena jam selesai telah terlewati.', 'created_at' => $now,
                ]);

                $this->info("[OVERDUE] Terkirim untuk Ticket #{$ticket->ticket_number}");
                $count++;
            }
        }
    }

    private function alreadySent(int $ticketId, string $level): bool
    {
        return TicketReminderLog::where('ticket_id', $ticketId)
            ->where('reminder_level', $level)
            ->exists();
    }

    private function sendTelegram(string $botToken, string $chatId, string $text): bool
    {
        $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ]);

        if (!$response->successful()) {
            $plainText = str_replace(['*', '_', '`'], '', $text);
            $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $plainText,
            ]);
        }

        if (!$response->successful()) {
            Log::error("Gagal kirim Telegram", ['body' => $response->body()]);
        }

        return $response->successful();
    }

    private function logReminder(Ticket $ticket, string $level, string $text, Carbon $now): void
    {
        TicketReminderLog::create([
            'ticket_id' => $ticket->id,
            'staff_id' => $ticket->assigned_staff_id,
            'reminder_level' => $level,
            'message' => $text,
            'sent_at' => $now,
        ]);
    }
}