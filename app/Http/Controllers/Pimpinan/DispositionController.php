<?php

namespace App\Http\Controllers\Pimpinan;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Models\TicketLog;
use App\Jobs\SendTelegramJob;
use Illuminate\Http\Request;

class DispositionController extends Controller
{
    // 1. Ambil tiket yang menunggu disposisi
    public function getPendingTickets()
    {
        $tickets = Ticket::with(['service', 'requester'])
            ->where('status', 'pending_approval') // Status baru untuk menunggu pimpinan
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($tickets);
    }

    // 2. Ambil list staff berdasarkan kategori layanan (yang FREE & BIDANG SESUAI)
    public function getAvailableStaff($service_id)
    {
        $ticket = Ticket::find($service_id); // ambil tiket untuk cek kategori
        $category = $ticket->service->category; // 'IT', 'Zoom', 'Command Center'

        $staff = User::availableForDisposition($category)->get(['id', 'name', 'nip', 'bidang']);
        
        // Tambahkan info jika ada staff yang cuti/sakit (untuk ditampilkan terkunci di FE)
        $allStaff = User::where('role', 'staff')->where('bidang', $category)->get(['id', 'name', 'nip', 'bidang', 'attendance_status']);
        
        $formatted = $allStaff->map(function($s) use ($staff) {
            $isAvailable = $staff->contains('id', $s->id);
            return [
                'id' => $s->id,
                'name' => $s->name,
                'nip' => $s->nip,
                'bidang' => $s->bidang,
                'is_available' => $isAvailable,
                'is_absent' => in_array($s->attendance_status, ['Cuti', 'Sakit', 'Izin']),
                'absent_reason' => (in_array($s->attendance_status, ['Cuti', 'Sakit', 'Izin'])) ? "Sedang {$s->attendance_status}" : null
            ];
        });

        return response()->json($formatted);
    }

    // 3. Pimpinan Menunjuk Staff
    public function assignStaff(Request $request, $ticket_id)
    {
        $request->validate(['staff_id' => 'required|exists:users,id']);
        
        $ticket = Ticket::find($ticket_id);
        $staff = User::find($request->staff_id);

        // Cegah jika staff cuti/sakit
        if (in_array($staff->attendance_status, ['Cuti', 'Sakit', 'Izin'])) {
            return response()->json(['message' => 'Gagal. Staff sedang berhalangan hadir (Cuti/Sakit).'], 403);
        }

        // Cegah jika bidang tidak sesuai
        if ($staff->bidang !== $ticket->service->category) {
            return response()->json(['message' => 'Gagal. Bidang staff tidak sesuai dengan kategori layanan.'], 403);
        }

        $ticket->assigned_staff_id = $staff->id;
        $ticket->assigned_by_role = 'pimpinan';
        $ticket->disposed_at = now();
        $ticket->status = 'assigned';
        $ticket->save();

        TicketLog::create([
            'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
            'action' => 'DISPOSED', 'description' => "Pimpinan menunjuk Staff {$staff->name} untuk mengerjakan layanan.", 'created_at' => now(),
        ]);

        // Notif ke Staff (PENTING: Kasih tau siapa yang nunjuk)
        if ($staff->telegram_chat_id) {
            SendTelegramJob::dispatch(
                "📋 *ANDA DITUNJUK PIMPINAN*\n━━━━━━━━━━━━━━━━━━━\nTicket: #{$ticket->ticket_number}\nLayanan: {$ticket->service->name}\nDitunjuk oleh: *Pimpinan*\n━━━━━━━━━━━━━━━━━━━\n_Silakan buka aplikasi untuk memproses._", 
                $staff->telegram_chat_id
            );
        }

        return response()->json(['message' => 'Berhasil menunjuk staff.', 'data' => $ticket]);
    }
}