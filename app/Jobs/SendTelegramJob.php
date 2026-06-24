<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class SendTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $message;

    public function __construct($message)
    {
        $this->message = is_string($message) ? $message : json_encode($message);
    }

    public function handle(): void
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID');

        if (empty($botToken) || empty($chatId)) {
            Log::error("TELEGRAM ERROR: BOT_TOKEN atau CHAT_ID kosong!", [
                'has_token' => !empty($botToken),
                'has_chat_id' => !empty($chatId),
            ]);
            return;
        }

        try {
            // ✅ STEP 1: Coba kirim dengan Markdown
            $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $this->message,
                'parse_mode' => 'Markdown',
            ]);

            // ✅ LOG RESPONSE PERTAMA
            Log::info("TELEGRAM ATTEMPT 1 (Markdown):", [
                'status' => $response->status(),
                'success' => $response->successful(),
                'body' => $response->body(),
            ]);

            // ✅ STEP 2: Kalau gagal, kirim polos tanpa Markdown
            if (!$response->successful()) {
                $plainText = strip_tags($this->message);
                $plainText = str_replace(['*', '_', '`', '[', ']', '(', ')', '#'], '', $plainText);
                
                $response2 = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $plainText,
                ]);

                // ✅ LOG RESPONSE KEDUA
                Log::info("TELEGRAM ATTEMPT 2 (Plain):", [
                    'status' => $response2->status(),
                    'success' => $response2->successful(),
                    'body' => $response2->body(),
                ]);
            }

        } catch (\Exception $e) {
            Log::error("TELEGRAM EXCEPTION: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}