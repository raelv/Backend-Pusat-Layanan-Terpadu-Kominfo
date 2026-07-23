<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Ticket;
use Carbon\Carbon;
use App\Jobs\SendTelegramJob;

class CheckOverdueScheduleCommand extends Command
{
    protected $signature = 'schedule:check-overdue';
    protected $description = 'Cek jadwal Zoom/CC: kirim notif jika lewat jam mulai, buat log jika lewat jam selesai.';

    public function handle()
    {
        $now = Carbon::now('Asia/Makassar');

        // ==========================================
        // 1. LOGIKA 1: NOTIFIKASI LEWAT JAM MULAI (Sekali Kirim)
        // ==========================================
        $lewatMulai = Ticket::with(['service', 'requester'])
            ->whereNull('assigned_staff_id')
            ->whereNull('overdue_notified_at') // Cek flag ini
            ->whereHas('service', function ($q) {
                $q->whereRaw("LOWER(category) IN ('zoom', 'command center')");
            })
            ->whereNotNull('schedule_start')
            ->where('schedule_start', '<=', $now)
            ->get();

        foreach ($lewatMulai as $ticket) {
            $kegiatan = $ticket->form_data['nama_acara'] ?? $ticket->form_data['nama_kegiatan'] ?? $ticket->service->name;
            $opdName = $ticket->requester->name ?? 'OPD';
            $category = $ticket->service->category;
            $jamMulai = $ticket->schedule_start->format('H:i');
            $jamSekarang = $now->format('H:i');

            $pesan = "⚠️ *PERINGATAN LAYANAN TERLEWAT JADWAL* ⚠️\n" .
                     "┌─ Jenis Layanan: *{$category}*\n" .
                     "├─ Kegiatan: {$kegiatan}\n" .
                     "├─ Pemohon: *{$opdName}*\n" .
                     "├─ Jadwal Mulai: {$jamMulai} WITA\n" .
                     "└─ Saat ini: {$jamSekarang} WITA\n\n" .
                     "Status: BELUM DIDISPOSISI.\n" .
                     "Mohon segera cek dashboard disposisi. Layanan ini telah ditandai *Lewat Jadwal* di tabel agar mudah ditemukan.\n" .
                     "_Tindakan segera diperlukan untuk meminimalisir dampak keterlambatan._";

            SendTelegramJob::dispatch($pesan);
            $ticket->update(['overdue_notified_at' => $now]);
            
            $this->info("Notif terlambat dikirim untuk Ticket #{$ticket->ticket_number}");
        }

        // ==========================================
        // 2. LOGIKA 2: LOG EXPIRED SAAT JAM SELESAI TERLEWAT (Pisah Query)
        // ==========================================
        $lewatSelesai = Ticket::with(['service'])
            ->whereNull('assigned_staff_id')
            ->whereHas('service', function ($q) {
                $q->whereRaw("LOWER(category) IN ('zoom', 'command center')");
            })
            ->whereNotNull('schedule_end')
            ->where('schedule_end', '<=', $now)
            ->whereDoesntHave('logs', function ($q) {
                $q->where('action', 'EXPIRED');
            })
            ->get();

        foreach ($lewatSelesai as $ticket) {
            \App\Models\TicketLog::create([
                'ticket_id' => $ticket->id,
                'user_id' => null,
                'action' => 'EXPIRED',
                'description' => "Tidak ada disposisi dari pimpinan/admin hingga waktu pelaksanaan berakhir. Status permohonan otomatis berubah menjadi Expired / Kadaluarsa.",
                'created_at' => $now,
            ]);

            $this->info("Log EXPIRED dicatat untuk Ticket #{$ticket->ticket_number}");
        }

        return 0;
    }
}