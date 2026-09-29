<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Leave;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ResetExpiredLeaveStatuses extends Command
{
    protected $signature = 'attendance:reset-expired';
    protected $description = 'Reset attendance_status staff ke Masuk setelah periode cuti/izin/sakit berakhir';

    public function handle()
    {
        $today = Carbon::today('Asia/Makassar')->toDateString();

        $expiredLeaves = Leave::where('status', 'active')
            ->whereDate('end_date', '<', $today)
            ->get();

        foreach ($expiredLeaves as $leave) {
            $leave->status = 'completed';
            $leave->save();
        }

        $resetCount = User::where('role', 'staff')
            ->whereIn('attendance_status', ['Cuti', 'Izin', 'Sakit'])
            ->whereNotExists(function ($q) use ($today) {
                $q->select(DB::raw(1))
                    ->from('leaves')
                    ->whereColumn('leaves.user_id', 'users.id')
                    ->where('leaves.status', 'active')
                    ->whereDate('leaves.start_date', '<=', $today)
                    ->whereDate('leaves.end_date', '>=', $today);
            })
            ->update(['attendance_status' => 'Masuk']);

        $this->info("Cuti/izin ditutup: {$expiredLeaves->count()} | Status direset ke Masuk: {$resetCount}");

        return 0;
    }
}