<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TelegramWebhookController extends Controller
{
    /**
     * Dipanggil oleh Telegram Server saat ada pesan masuk
     */
    public function handleWebhook(Request $request)
    {
        $update = $request->all();
        
        if (isset($update['message']['text'])) {
            $chatId = $update['message']['chat']['id'];
            $text = trim($update['message']['text']);
            
            // Cek apakah ini Deep Link (/start TOKEN)
            if (Str::startsWith($text, '/start ')) {
                $token = substr($text, 7); // Ambil string setelah "/start "
                $this->handleDeepLinkBinding($chatId, $token);
            } elseif ($text === '/start') {
                $this->handleNormalStart($chatId);
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Logika binding saat OPD klik Deep Link
     */
    private function handleDeepLinkBinding($chatId, $token)
    {
        $cacheKey = 'telegram_binding_' . $token;
        $userId = Cache::get($cacheKey);

        if (!$userId) {
            $this->sendMessage($chatId, "❌ *Token Tidak Valid*\n\nLink yang kamu gunakan sudah kadaluarsa atau tidak sah. Silakan coba lagi dari Website.");
            return;
        }

        $user = User::find($userId);
        
        if (!$user) {
            Cache::forget($cacheKey);
            return;
        }

        // Cek apakah chat ID ini sudah dipakai user lain
        $existingUser = User::where('telegram_chat_id', $chatId)->where('id', '!=', $userId)->first();
        if ($existingUser) {
            $this->sendMessage($chatId, "❌ *Akun Sudah Terpakai*\n\nAkun Telegram ini sudah terhubung dengan pengguna lain. Hubungi Admin jika ada kendala.");
            return;
        }

        // SIMPAN CHAT ID KE DATABASE
        $user->telegram_chat_id = $chatId;
        $user->save();

        // HAPUS TOKEN AGAR TIDAK BISA DIPAKAI LAGI
        Cache::forget($cacheKey);

        $this->sendMessage($chatId, "✅ *Berhasil Terhubung!*\n\nAkun Telegram kamu sekarang sudah terhubung dengan akun Website.\n\nNama: *{$user->name}*\nNIP: `{$user->nip}`\n\nMulai sekarang, kamu akan menerima notifikasi pribadi mengenai perkembangan layanan di sini.");
    }

    /**
     * Logic jika user cuma ketik /start biasa
     */
    private function handleNormalStart($chatId)
    {
        $this->sendMessage($chatId, "👋 *Halo! Bot Notifikasi Kominfo*\n\nBot ini terhubung dengan Sistem Layanan Kominfo.\n\nUntuk menghubungkan akun Telegram pribadimu, silakan gunakan tombol *'Hubungkan Telegram'* yang ada di halaman Profil pada Website.");
    }

    /**
     * Generate Token Deep Link (Dipanggil oleh FE)
     */
    public function generateToken(Request $request)
    {
        $user = auth()->user();
        
        // Buat token unik
        $token = Str::uuid()->toString();
        
        // Simpan token ke Cache selama 30 menit
        Cache::put('telegram_binding_' . $token, $user->id, now()->addMinutes(30));

        $botUsername = env('TELEGRAM_BOT_USERNAME');
        $deepLink = "https://t.me/{$botUsername}?start={$token}";

        return response()->json([
            'deep_link' => $deepLink
        ]);
    }

    /**
     * Putuskan Koneksi Telegram (Dipanggil oleh FE)
     */
    public function unlinkTelegram(Request $request)
    {
        auth()->user()->update(['telegram_chat_id' => null]);
        
        return response()->json([
            'message' => 'Koneksi Telegram berhasil diputuskan.'
        ]);
    }

    /**
     * Helper kirim pesan Telegram
     */
    private function sendMessage($chatId, $text)
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        
        Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ]);
    }
}