<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Staff\AttendanceController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\TicketCommentController;
use App\Http\Controllers\Api\TicketLogController;
use App\Models\TicketReminderLog;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\ZoomLinkController;
use App\Http\Controllers\ReportController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// 1. LANDING PAGE (PUBLIC, TANPA LOGIN)
Route::get('/public/schedules', [LandingController::class, 'getSchedules']);
    // ✅ KALENDER PUBLIK (Untuk Cek Ketersediaan Jadwal Zoom & Command Center)
    Route::get('/public/calendar', function (Illuminate\Http\Request $request) {
        $request->validate([
            'month' => 'required|date_format:Y-m', // Format: 2024-08
            'category' => 'nullable|in:zoom,command_center'
        ]);

        $monthStart = \Carbon\Carbon::createFromFormat('Y-m', $request->month, 'Asia/Makassar')->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $query = \App\Models\Ticket::whereHas('service', function($q) use ($request) {
            $q->whereIn('category', ['zoom', 'command_center']);
            if ($request->filled('category')) {
                $q->where('category', $request->category);
            }
        })
        ->whereIn('status', ['pending', 'queued', 'assigned', 'in_progress', 'approved_admin'])
        ->whereNotNull('schedule_start')
        ->whereBetween('schedule_start', [$monthStart, $monthEnd]);

        // Kelompokkan berdasarkan tanggal saja (1 hari bisa ada beberapa booking)
        $bookedDates = $query->get()->groupBy(function ($ticket) {
            return $ticket->schedule_start->format('Y-m-d');
        })->map(function ($tickets, $date) {
            return [
                'date' => $date,
                'is_fully_booked' => $tickets->count() >= 3, // Optional: Anggap penuh jika 3 tiket di hari itu (sesuaikan jika ada aturan kapasitas)
                'bookings' => $tickets->map(function ($t) {
                    return [
                        'service' => $t->service->name,
                        'time' => $t->schedule_start->format('H:i') . ' - ' . $t->schedule_end->format('H:i')
                    ];
                })
            ];
        })->values()->toArray();

        return response()->json([
            'month' => $request->month,
            'data' => $bookedDates
        ]);
    });

// ✅ TAMBAHKAN INI: Endpoint Info Jam Operasional (Sesuai Permintaan Dokumentasi)
Route::get('/public/operational-hours', function () {
    return response()->json([
        "message" => "Informasi jam operasional layanan",
        "data" => [
            "IT" => [
                "hari_kerja" => "Senin - Jumat",
                "jam_operasional" => "24 Jam"
            ],
            "Zoom" => [
                "hari_kerja" => "Senin - Jumat",
                "jam_operasional" => "24 Jam"
            ],
            "Command Center" => [
                "hari_kerja" => "Senin - Jumat",
                "jam_operasional" => "07.30 - 16.00 WITA"
            ]
        ]
    ]);
});

// ✅ LOGIN DENGAN THROTTLE
Route::post('/login', function (Request $request) {
    if (empty($request->login_id) || empty($request->password)) {
        return response()->json([
            'message' => 'Email/NIP dan Password wajib diisi.'
        ], 422);
    }

    $password = $request->password;
    $missing = [];

    if (strlen($password) < 8) {
        $missing[] = 'minimal 8 karakter';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $missing[] = 'huruf kecil';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $missing[] = 'huruf besar';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $missing[] = 'angka';
    }
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{}|;\':",.<>?\/\\\\`~]/', $password)) {
        $missing[] = 'karakter khusus';
    }

    if (!empty($missing)) {
        $pesan = 'Password tidak valid. Harus mengandung ' . implode(', ', $missing) . '.';
        return response()->json([
            'message' => $pesan
        ], 422);
    }

    // Sisa kode login tetap sama...
    $loginId = trim($request->login_id);
    $fieldType = filter_var($loginId, FILTER_VALIDATE_EMAIL) ? 'email' : 'nip';

    if ($fieldType === 'email' && !str_ends_with($loginId, '@bontangkota.go.id')) {
        return response()->json(['message' => 'Akses Ditolak. Hanya email @bontangkota.go.id.'], 403);
    }

    $user = \App\Models\User::where($fieldType, $loginId)->first();

    if (!$user || !\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
        return response()->json([
            'message' => 'Email/NIP atau Password tidak valid'
        ], 401);
    }
    
    $token = $user->createToken('api-token')->plainTextToken;
    
    $userData = $user->toArray();
    if ($user->role === 'pimpinan') {
        $userData['name'] = 'Pimpinan';
        $userData['bidang'] = 'Kepala Dinas Kominfo';
    }

    return response()->json([
        'message' => 'Login Berhasil', 
        'user' => $userData, 
        'token' => $token
    ]);
})->middleware('throttle:10,1');

// PUBLIC ROUTE UNTUK PREVIEW PDF
Route::get('tickets/{ticket}/preview-pdf', [TicketController::class, 'previewPdf']);

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handleWebhook']);

// ========================================================
// PROTECTED ROUTES (Butuh Token)
// ========================================================
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logout berhasil']);
    });

    Route::get('/user/profile', function (Request $request) {
        $user = $request->user();
        $userData = $user->toArray();
        
        if ($user->role === 'pimpinan') {
            $userData['name'] = 'Pimpinan';
            $userData['bidang'] = 'Kepala Dinas Kominfo';
        }
        
        return response()->json($userData);
    });
    
    // --- CEK JAM OPERASIONAL & AKSES CEPAT LAYANAN ---
    // --- AUDIT TRAIL ---
    Route::get('/tickets/{ticket}/logs', [TicketLogController::class, 'index']);

    Route::get('/quick-services', function () {
        $now = \Carbon\Carbon::now('Asia/Makassar');
        $startTime = \Carbon\Carbon::createFromTime(7, 30, 0, 'Asia/Makassar');
        $endTime = \Carbon\Carbon::createFromTime(22, 0, 0, 'Asia/Makassar');

        $isOperational = $now->gte($startTime) && $now->lte($endTime);

        $services = \App\Models\Service::select('id', 'name', 'slug', 'description')
            ->where('is_active', true)
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'is_operational' => $isOperational,
            'closure_message' => 'Layanan pengajuan telah ditutup dikarenakan jam operasionalnya telah selesai. (Jam Operasional: 07:30 - 22:00 WIB)',
            'services' => $services
        ]);
    });

    // --- TELEGRAM SETTINGS ---
    Route::prefix('user')->group(function () {
        Route::post('/generate-telegram-token', [TelegramWebhookController::class, 'generateToken'])->middleware('throttle:5,1');
        Route::post('/unlink-telegram', [TelegramWebhookController::class, 'unlinkTelegram']);
        Route::get('/telegram-status', function () {
            $user = auth()->user();
            return response()->json([
                'is_connected' => !is_null($user->telegram_chat_id),
            ]);
        });
    });

    // --- DAFTAR AKUN (UNTUK DROPDOWN UMUM) ---
    Route::get('/users', function (Request $request) {
        $query = \App\Models\User::select('id', 'name', 'email', 'role', 'nip', 'attendance_status');
        if ($request->has('role')) {
            $query->where('role', $request->role);
        }
        return $query->orderBy('name', 'asc')->get();
    });

    // --- EXPORT LAMA ---
    Route::get('tickets/export-excel', [TicketController::class, 'exportExcel'])->middleware('throttle:10,1');
    Route::get('tickets/{ticket}/export-word', [TicketController::class, 'exportWord'])->middleware('throttle:10,1');

    // --- SLA & VALIDASI LAYANAN ---
    Route::get('/sla-info', function () {
        return response()->json([
            'data' => [
                ['category' => 'it', 'label' => 'Layanan IT / Website', 'rule' => 'Minimal 3 Bulan (90 Hari)', 'type' => 'minimum'],
                ['category' => 'zoom', 'label' => 'Layanan Zoom', 'rule' => 'Maksimal 6 Jam', 'type' => 'maximum'],
                ['category' => 'command_center', 'label' => 'Layanan Command Center', 'rule' => 'Maksimal 6 Jam', 'type' => 'maximum']
            ]
        ]);
    });

    // Endpoint Monitoring SLA 
    Route::get('/admin/sla-monitoring', function (Illuminate\Http\Request $request) {
        $query = \App\Models\Ticket::with(['service', 'staff', 'requester']);
        
        if ($request->has('sla_status') && $request->sla_status === 'overdue') {
            $query->whereNotNull('due_date')->where('due_date', '<', now())->whereNotIn('status', ['completed', 'rejected', 'cancelled']);
        } elseif ($request->has('sla_status') && $request->sla_status === 'approaching') {
            $query->whereNotNull('due_date')->where('due_date', '>', now())->whereNotIn('status', ['completed', 'rejected', 'cancelled']);
        }

        $tickets = $query->orderBy('due_date', 'asc')->get();

        $formatted = $tickets->map(function ($ticket) {
            $dueDate = \Carbon\Carbon::parse($due_date = $ticket->due_date);
            $now = now();
            $isOverdue = $now->gt($dueDate);
            $remainingSeconds = $now->diffInSeconds($dueDate);

            if ($isOverdue) {
                $timeText = "Lewat " . $dueDate->diffForHumans($now);
            } else {
                $days = floor($remainingSeconds / 86400);
                $hours = floor(($remainingSeconds % 86400) / 3600);
                $timeText = ($days > 0 ? "{$days} Hari " : "") . "{$hours} Jam";
            }

            return [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'requester' => $ticket->requester->name ?? 'Unknown',
                'service' => $ticket->service->name ?? 'Unknown',
                'category' => $ticket->service->category ?? null,
                'due_date' => $ticket->due_date->toDateTimeString(),
                'status' => $ticket->status,
                'is_overdue' => $isOverdue,
                'remaining_time' => $timeText
            ];
        });

        return response()->json([
            'message' => 'Data monitoring SLA berhasil diambil',
            'data' => $formatted
        ]);
    });

    // --- TIKET & TRACKING ---
    // Pisahkan POST (Buat Tiket) untuk diberi Throttle
    Route::post('/tickets', [TicketController::class, 'store'])->middleware('throttle:10,1');
    
    // Selain POST, method lainnya bebas (GET, PUT, DELETE)
    Route::match(['put', 'patch', 'delete'], 'tickets/{ticket}', [TicketController::class, 'update']);
    Route::get('tickets/{ticket}', [TicketController::class, 'show']);
    Route::get('/tickets', [TicketController::class, 'index']);

    Route::match(['put', 'post'], 'tickets/{ticket}/status', [TicketController::class, 'updateStatus'])->middleware('throttle:30,1');
    Route::get('/active-schedules', [TicketController::class, 'getActiveSchedules']);
    Route::get('tickets/{id}/resubmit-data', [TicketController::class, 'getResubmitData']); 

    // --- DISKUSI / KOMENTAR ---
    Route::get('tickets/{ticket}/comments', [TicketCommentController::class, 'index']);
    Route::post('tickets/{ticket}/comments', [TicketCommentController::class, 'store'])->middleware('throttle:30,1'); // Cegah flood komentar

    // --- SKM & DOWNLOAD ---
    Route::post('tickets/{ticket}/skm', [TicketController::class, 'submitSKM']);
    Route::get('tickets/{ticket}/download', [TicketController::class, 'downloadPdf']);
    
    // ✅ TAMBAHKAN INI: Endpoint Download File Surat Permohonan (Aman)
    Route::get('tickets/{ticket}/download-surat', function ($id) {
        $ticket = \App\Models\Ticket::find($id);
        if (!$ticket || !$ticket->surat_permohonan_path) {
            return response()->json(['message' => 'File tidak ditemukan'], 404);
        }

        // Pastikan hanya pemohon, admin, atau staf terkait yang bisa download
        $user = auth()->user();
        if (!in_array($user->role, ['admin', 'pimpinan']) && $ticket->user_id !== $user->id && $ticket->assigned_staff_id !== $user->id) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        return \Illuminate\Support\Facades\Storage::download($ticket->surat_permohonan_path);
    });

    // --- LAPORAN KOLEKTIF ---
    Route::get('/reports/export', [ReportController::class, 'getCollectiveData']);
    Route::get('/reports/export-pdf', [ReportController::class, 'exportCollectivePdf'])->middleware('throttle:10,1');
    Route::get('/reports/export-excel', [ReportController::class, 'exportCollectiveExcel'])->middleware('throttle:10,1');
    Route::post('/reports/export-word', [ReportController::class, 'exportCollectiveWord'])->middleware('throttle:10,1');

    // --- ROUTE KHUSUS PIMPINAN & ADMIN (DIBATASI ROLE) ---
    Route::prefix('pimpinan')->middleware('role:pimpinan,admin')->group(function () {
        Route::get('/dashboard', [App\Http\Controllers\Pimpinan\DashboardController::class, 'index']);
        Route::get('/leaves', [App\Http\Controllers\Pimpinan\DashboardController::class, 'getLeaves']);
        Route::get('/dispositions/pending', [App\Http\Controllers\Pimpinan\DashboardController::class, 'getPendingDispositions']);
        Route::get('/dispositions/expired', [App\Http\Controllers\Pimpinan\DashboardController::class, 'getExpiredDispositions']); 
        Route::get('/dispositions/staff/{service_id}', [App\Http\Controllers\Pimpinan\DashboardController::class, 'getAvailableStaffByService']);
        Route::post('/dispositions/assign/{ticket_id}', [App\Http\Controllers\Pimpinan\DashboardController::class, 'assignStaff'])->middleware('throttle:20,1');
        Route::post('/dispositions/reject/{ticket_id}', [App\Http\Controllers\Pimpinan\DashboardController::class, 'rejectTicket'])->middleware('throttle:20,1');
    });

    // --- ROUTE KHUSUS STAFF (DIBATASI ROLE) ---
    Route::prefix('staff')->middleware('role:staff')->group(function () {
        Route::get('/leaves', [AttendanceController::class, 'index']);
        Route::post('/leave', [AttendanceController::class, 'submitLeave'])->middleware('throttle:5,1');
        Route::put('/leaves/{id}', [AttendanceController::class, 'update']);
        Route::delete('/leaves/{id}', [AttendanceController::class, 'destroy']);
        Route::get('/zoom-links/available', [App\Http\Controllers\Admin\ZoomLinkController::class, 'getAvailableLinks']); 
        
        Route::post('/tickets/{ticket}/approve-reject', [TicketController::class, 'processByStaff'])->middleware('throttle:20,1');
        
        Route::get('/reminders', function () {
            $staffId = Auth::id();
            return TicketReminderLog::with(['ticket.service'])
                ->where('staff_id', $staffId)
                ->orderBy('sent_at', 'desc')
                ->take(20)
                ->get();
        });
    });

    // --- ROUTE KHUSUS ADMIN (DIBATASI ROLE) ---
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/staff-monitoring', [DashboardController::class, 'monitorStaff']);
        
        Route::get('/bidangs', [UserManagementController::class, 'getMasterBidangs']);
        
        Route::get('/staff', function (Illuminate\Http\Request $request) {
            $query = \App\Models\User::where('role', 'staff');

            if ($request->has('specialization') && !empty($request->specialization)) {
                $query->whereRaw("service_access @> ?", ['["' . strtolower($request->specialization) . '"]']);
            }

            if ($request->has('status') && !empty($request->status)) {
                $query->where('attendance_status', $request->status);
            }

            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'ILIKE', "%{$search}%")
                      ->orWhere('nip', 'ILIKE', "%{$search}%");
                });
            }

            $staffs = $query->with('bidangs')->orderBy('name', 'asc')->get();

            $formatted = $staffs->map(function ($staff) {
                return [
                    'id' => $staff->id,
                    'name' => $staff->name,
                    'nip' => $staff->nip,
                    'email' => $staff->email,
                    'role' => $staff->role,
                    'bidang' => $staff->bidang,
                    'bidangs' => $staff->bidangs,
                    'service_access' => $staff->service_access ?? [],
                    'attendance_status' => $staff->attendance_status,
                    'active_task_count' => $staff->active_task_count ?? 0,
                    'is_overloaded' => $staff->is_overloaded ?? false
                ];
            });

            return response()->json([
                'message' => 'Data staff berhasil diambil',
                'data' => $formatted
            ]);
        });

        Route::put('/staff/{id}', function (Request $request, $id) {
            $user = \App\Models\User::find($id);
            if (!$user) {
                return response()->json(['message' => 'Staff tidak ditemukan'], 404);
            }

            $request->validate([
                'role' => 'sometimes|in:staff,admin',
                'bidang_ids' => 'sometimes|array',
                'bidang_ids.*' => 'exists:bidangs,id',
                'service_access' => 'sometimes|array',
                'service_access.*' => 'in:it,zoom,command_center'
            ]);

            if ($request->has('role')) {
                $user->role = $request->role;
            }
            if ($request->has('service_access')) {
                $user->service_access = $request->service_access;
            }
            
            if ($request->has('bidang_ids')) {
                $user->bidangs()->sync($request->bidang_ids);
            }

            $user->save();

            return response()->json([
                'message' => 'Data operasional staff berhasil diperbarui',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'nip' => $user->nip,
                    'email' => $user->email,
                    'role' => $user->role,
                    'bidangs' => $user->bidangs()->get(),
                    'service_access' => $user->service_access,
                    'attendance_status' => $user->attendance_status
                ]
            ]);
        })->middleware('throttle:20,1');

        // Dispositions Admin (Diizinkan juga untuk Pimpinan, jadi kita taruh di luar sini atau pakai alias khusus jika perlu)
        // Kalau Admin saja yang boleh, biarkan di sini. Kalau Pimpinan juga boleh, pindahkan ke grup pimpinan di atas.
        Route::get('/dispositions/pending', [DashboardController::class, 'getPendingDispositions']);
        Route::get('/dispositions/expired', [DashboardController::class, 'getExpiredDispositions']);
        Route::post('/dispositions/assign/{ticket_id}', [DashboardController::class, 'assignStaff'])->middleware('throttle:20,1');
        Route::post('/dispositions/reject/{ticket_id}', [DashboardController::class, 'rejectTicket'])->middleware('throttle:20,1'); 
        
        Route::get('/dispositions/staff/{service_id}', [DashboardController::class, 'getAvailableStaffByService']);
        Route::apiResource('services', ServiceController::class);
        
        // MANAJEMEN PENGGUNA
        Route::put('/users/{id}', function (Request $request, $id) {
            $user = \App\Models\User::find($id);
            if (!$user) {
                return response()->json(['message' => 'User tidak ditemukan'], 404);
            }

            $request->validate([
                'role' => 'sometimes|in:staff,admin',
                'bidang_ids' => 'sometimes|array',
                'bidang_ids.*' => 'exists:bidangs,id',
                'access_list' => 'sometimes|array',
                'access_list.*' => 'in:it,zoom,command_center'
            ]);

            $warningMessage = null;
            if ($user->active_task_count > 0) {
                $warningMessage = "PERINGATAN: User ini sedang mengerjakan {$user->active_task_count} tugas aktif. Perubahan hak akses saat ini berisiko mengganggu tugas yang sedang berjalan.";
            }

            if ($request->has('role')) {
                if (!in_array($user->role, ['staff', 'admin'])) {
                    return response()->json(['message' => 'Role Pimpinan dan OPD tidak dapat diubah di sini.'], 403);
                }
                
                if ($request->role === 'staff') {
                    if (empty($request->access_list)) {
                        return response()->json(['message' => 'Hak Akses Layanan wajib dipilih jika role Staff.'], 422);
                    }
                }
                $user->role = $request->role;
            }

            if ($request->has('bidang_ids')) {
                $user->bidangs()->sync($request->bidang_ids);
            }

            if ($request->has('access_list')) {
                $user->service_access = $request->access_list;
            }

            $user->save();

            return response()->json([
                'message' => 'Data user berhasil diperbarui',
                'warning' => $warningMessage,
                'data' => $user->load('bidangs')
            ]);
        })->middleware('throttle:20,1');

        Route::get('/users', [UserManagementController::class, 'index']);
        Route::get('/users/role-options', [UserManagementController::class, 'getRoleOptions']);
        Route::get('/users/bidang-options', [UserManagementController::class, 'getBidangOptions']);
        Route::put('/users/{id}/role', [UserManagementController::class, 'updateRole'])->middleware('throttle:20,1');
        Route::put('/users/{id}/bidang', [UserManagementController::class, 'updateBidang'])->middleware('throttle:20,1');
        Route::put('/users/{id}/service-access', [UserManagementController::class, 'updateServiceAccess'])->middleware('throttle:20,1');
        Route::put('/users/{id}/bidang', [UserManagementController::class, 'syncUserBidangs']);

        // ✅ KICK USER (Force Logout dari semua perangkat)
        Route::post('/users/{id}/kick', function ($id) {
            $user = \App\Models\User::find($id);
            if (!$user) {
                return response()->json(['message' => 'User tidak ditemukan'], 404);
            }
            $user->tokens()->delete();
            return response()->json(['message' => "Berhasil mengeluarkan user {$user->name} dari sistem."]);
        });
        
        // MANAJEMEN IZIN/CUTI/SAKIT
        Route::get('/leaves', function() {
            return \App\Models\Leave::with('user:id,name,role,attendance_status')->get();
        });
        Route::put('/leaves/{id}/approve', [DashboardController::class, 'approveLeave'])->middleware('throttle:20,1');
        Route::put('/leaves/{id}/reject', [DashboardController::class, 'rejectLeave'])->middleware('throttle:20,1');

        // MANAJEMEN LINK ZOOM
        Route::get('/zoom-links', [App\Http\Controllers\Admin\ZoomLinkController::class, 'index']);
        Route::post('/zoom-links', [App\Http\Controllers\Admin\ZoomLinkController::class, 'store'])->middleware('throttle:10,1');
        Route::put('/zoom-links/{id}', [App\Http\Controllers\Admin\ZoomLinkController::class, 'update'])->middleware('throttle:20,1');
        Route::delete('/zoom-links/{id}', [App\Http\Controllers\Admin\ZoomLinkController::class, 'destroy'])->middleware('throttle:10,1');
    });
});