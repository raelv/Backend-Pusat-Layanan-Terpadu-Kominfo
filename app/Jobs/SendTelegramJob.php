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
        $this->chatId = $chatId;
    }

public function handle(): void
{
    $botToken = config('services.telegram.bot_token');
    $chatId = $this->chatId ?? config('services.telegram.chat_id');

    $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
        'chat_id' => $chatId,
        'text' => $this->message,
        'parse_mode' => 'Markdown'
    ]);

    if (!$response->successful()) {
        $plainText = str_replace(['*', '_', '`'], '', $this->message);
        $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $plainText,
        ]);
    }

    if (!$response->successful()) {
        \Illuminate\Support\Facades\Log::error('SendTelegramJob gagal', [
            'chat_id' => $chatId,
            'body' => $response->body(),
        ]);
    }
}

public $tries = 1;
public $timeout = 30;
}