<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $chatId;
    public $message;

    // ✅ BISA TERIMA CHAT ID SPESIFIK (BUAT DM OPD)
    public function __construct($message, $chatId = null)
    {
        $this->message = is_string($message) ? $message : json_encode($message);
        $this->chatId = $chatId ?? env('TELEGRAM_CHAT_ID'); // Kalau null, pakai Group Staff
    }

    public function handle(): void
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');

        $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $this->chatId,
            'text' => $this->message,
            'parse_mode' => 'Markdown'
        ]);

        if (!$response->successful()) {
            $plainText = str_replace(['*', '_', '`'], '', $this->message);
            Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $plainText,
            ]);
        }
    }
}