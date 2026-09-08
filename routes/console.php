<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\SendZoomReminderJob;
use App\Models\Ticket;
use Carbon\Carbon;

Schedule::command('tickets:check-sla')->everyFiveMinutes();
Schedule::command('reminders:send-deadline')->everyMinute();
Schedule::command('schedule:check-overdue')->everyFiveMinutes();
Schedule::command('tickets:check-expired')->everyFiveMinutes();

// ✅ PENGINGAT ZOOM: 15 menit sebelum jadwal dimulai
Schedule::call(function () {
    $now = Carbon::now('Asia/Makassar')->addMinutes(15);
    
    $tickets = Ticket::with('service', 'zoomLink', 'staff', 'requester')
        ->whereHas('service', function ($q) {
            $q->where('category', 'zoom');
        })
        ->whereIn('status', ['assigned', 'in_progress', 'approved_admin'])
        ->whereNotNull('schedule_start')
        ->whereNotNull('schedule_end')
        ->whereRaw("schedule_start BETWEEN ? AND ?", [
            $now->copy()->subMinute(1)->format('Y-m-d H:i:s'),
            $now->copy()->addMinute(1)->format('Y-m-d H:i:s')
        ])
        ->get();

    foreach ($tickets as $ticket) {
        SendZoomReminderJob::dispatch($ticket->id, 'before_start');
    }
})->everyMinute();

// ✅ PENGINGAT ZOOM: Setelah jadwal selesai
Schedule::call(function () {
    $now = Carbon::now('Asia/Makassar');
    
    $tickets = Ticket::with('service', 'zoomLink', 'staff', 'requester')
        ->whereHas('service', function ($q) {
            $q->where('category', 'zoom');
        })
        ->whereIn('status', ['assigned', 'in_progress', 'approved_admin'])
        ->whereNotNull('schedule_end')
        ->whereRaw("schedule_end BETWEEN ? AND ?", [
            $now->copy()->subMinute(1)->format('Y-m-d H:i:s'),
            $now->copy()->addMinute(1)->format('Y-m-d H:i:s')
        ])
        ->get();

    foreach ($tickets as $ticket) {
        SendZoomReminderJob::dispatch($ticket->id, 'after_end');
    }
})->everyMinute();