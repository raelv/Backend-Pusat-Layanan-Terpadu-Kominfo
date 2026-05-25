<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log; // Import Log untuk penanganan error
use Telegram\Bot\Laravel\Facades\Telegram; // Import Telegram

class SendTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $message;
    public $userId; 

    public function __construct($message, $userId = null)
    {
        $this->message = $message;
        $this->userId = $userId;
    }

    public function handle(): void
    {
        try {
            // Ambil Chat ID dari .env
            $chatId = env('TELEGRAM_CHAT_ID');

            // Kirim Pesan ke Telegram
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $this->message,
                // Parse_mode aku HAPUS biar aman dari error karakter HTML
            ]);

        } catch (\Exception $e) {
            // Kalau gagal kirim (misal token salah atau no internet), catat error di Log
            Log::error("Gagal kirim notifikasi Telegram: " . $e->getMessage());
        }
    }
}