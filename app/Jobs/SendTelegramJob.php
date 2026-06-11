<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

class SendTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $message;

    public function __construct($message)
    {
        // Pastikan yang masuk adalah string murni
        $this->message = is_string($message) ? $message : json_encode($message);
    }

        public function handle(): void
    {
        $chatId = env('TELEGRAM_CHAT_ID');

        if (empty($chatId)) {
            Log::error("TELEGRAM ERROR: TELEGRAM_CHAT_ID di .env kosong!");
            return;
        }

        try {
            // TAMBAHKAN parse_mode => 'Markdown' SUPAYA TANDA BINTANG BISA JADI TEBAL
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $this->message,
                'parse_mode' => 'Markdown'
            ]);
        } catch (\Exception $e) {
            // FALLBACK: Kalau ada karakter yang bikin error Markdown, kirim ulang polos
            try {
                $plainText = strip_tags(str_replace(['*', '_'], '', $this->message));
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $plainText,
                ]);
            } catch (\Exception $innerE) {
                Log::error("Gagal kirim notifikasi Telegram: " . $innerE->getMessage());
            }
        }
    }
}