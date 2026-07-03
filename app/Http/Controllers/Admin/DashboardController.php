<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        try {
            // 1. Statistik Tiket
            $totalTickets = Ticket::count();
            $completedTickets = Ticket::where('status', 'completed')->count();
            $pendingTickets = Ticket::whereIn('status', ['pending', 'queued', 'pending_approval'])->count();

            // Hitung SLA
            $slaCompliance = $totalTickets > 0 ? round(($completedTickets / $totalTickets) * 100, 2) : 100;

            // 2. Data Staf (DIUBAH KEY-NYA JADI 'staff')
            $staffData = User::where('role', 'staff')->get()->map(function ($staff) {
                // Hitung tugas aktif manual
                $activeTaskCount = 0;
                try {
                    $activeTaskCount = $staff->assignedTasks()
                        ->whereIn('status', ['assigned', 'in_progress', 'approved_admin'])
                        ->count();
                } catch (\Exception $e) {}

                // Ambil tugas saat ini
                $currentTask = null;
                try {
                    $currentTask = $staff->assignedTasks()
                        ->whereIn('status', ['assigned', 'in_progress'])
                        ->first();
                } catch (\Exception $e) {}

                return [
                    'id' => $staff->id,
                    'name' => $staff->name,
                    'email' => $staff->email,
                    'role' => $staff->role,
                    'nip' => $staff->nip, // ✅ TAMBAHKAN BARI INI
                    'bidang' => $staff->bidang ?? '-',
                    'attendance_status' => $staff->attendance_status,
                    'active_task_count' => $activeTaskCount,
                    'current_task' => $currentTask
                ];
            });

            // 3. Return Response (KEY DISESUAIKAN)
            return response()->json([
                'stats' => [
                    'total' => $totalTickets,
                    'completed' => $completedTickets,
                    'pending' => $pendingTickets,
                    'sla_percentage' => $slaCompliance
                ],
                'staff' => $staffData  // <--- DULU 'staff_monitoring', SEKARANG DIUBAH JADI 'staff'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil data dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}