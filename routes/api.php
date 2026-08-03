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

// ✅ FIX LOGIN: Hapus auto-create, wajib akun valid
Route::post('/login', function (Request $request) {
    $request->validate([
        'login_id' => 'required', 
        'password' => 'required' 
    ]);

    $loginId = trim($request->login_id);
    $fieldType = filter_var($loginId, FILTER_VALIDATE_EMAIL) ? 'email' : 'nip';

    if ($fieldType === 'email' && !str_ends_with($loginId, '@bontangkota.go.id')) {
        return response()->json(['message' => 'Akses Ditolak. Hanya email @bontangkota.go.id.'], 403);
    }

    $user = \App\Models\User::where($fieldType, $loginId)->first();

    if (!$user) {
        return response()->json([
            'message' => 'Email/NIP atau Password tidak valid'
        ], 401);
    }

    if (!\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
        return response()->json([
            'message' => 'Email/NIP atau Password tidak valid'
        ], 401);
    }

    $token = $user->createToken('api-token')->plainTextToken;
    
    // ✅ MODIFIKASI OUTPUT KHUSUS PIMPINAN
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
});

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
        
        // ✅ MODIFIKASI OUTPUT KHUSUS PIMPINAN
        if ($user->role === 'pimpinan') {
            $userData['name'] = 'Pimpinan';
            $userData['bidang'] = 'Kepala Dinas Kominfo';
        }
        
        return response()->json($userData);
    });
    
    // --- CEK JAM OPERASIONAL & AKSES CEPAT LAYANAN ---
    Route::get('/operational-status', function () {
        $now = \Carbon\Carbon::now('Asia/Makassar');
        $startTime = \Carbon\Carbon::createFromTime(7, 30, 0, 'Asia/Makassar');
        $endTime = \Carbon\Carbon::createFromTime(22, 0, 0, 'Asia/Makassar');

        $isOperational = $now->gte($startTime) && $now->lte($endTime);

        return response()->json([
            'is_operational' => $isOperational,
            'message' => $isOperational ? 'Layanan aktif.' : 'Pengajuan layanan sedang ditutup. Jam operasional adalah 07:30 - 22:00 WIB.'
        ]);
    });

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
        Route::post('/generate-telegram-token', [TelegramWebhookController::class, 'generateToken']);
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

    // --- EXPORT LAMA (DIGANTI PATH AGAR TIDAK BENTROK DENGAN YANG KOLEKTIF)
    Route::get('tickets/export-excel', [TicketController::class, 'exportExcel']);
    Route::get('tickets/{ticket}/export-word', [TicketController::class, 'exportWord']);

    // --- SLA & VALIDASI LAYANAN ---
    
    // Endpoint Info SLA untuk Form OPD (TANPA LOGIN)
    Route::get('/sla-info', function () {
        return response()->json([
            'data' => [
                ['category' => 'it', 'label' => 'Layanan IT / Website', 'rule' => 'Minimal 3 Bulan (90 Hari)', 'type' => 'minimum'],
                ['category' => 'zoom', 'label' => 'Layanan Zoom', 'rule' => 'Maksimal 6 Jam', 'type' => 'maximum'],
                ['category' => 'command_center', 'label' => 'Layanan Command Center', 'rule' => 'Maksimal 6 Jam', 'type' => 'maximum']
            ]
        ]);
    });

    // Endpoint Monitoring SLA Khusus Admin
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
    Route::apiResource('tickets', TicketController::class);
    Route::match(['put', 'post'], 'tickets/{ticket}/status', [TicketController::class, 'updateStatus']);
    Route::get('/active-schedules', [TicketController::class, 'getActiveSchedules']);
    Route::get('tickets/{id}/resubmit-data', [TicketController::class, 'getResubmitData']); 

    // --- DISKUSI / KOMENTAR ---
    Route::get('tickets/{ticket}/comments', [TicketCommentController::class, 'index']);
    Route::post('tickets/{ticket}/comments', [TicketCommentController::class, 'store']);

    // --- SKM & DOWNLOAD ---
    Route::post('tickets/{ticket}/skm', [TicketController::class, 'submitSKM']);
    Route::get('tickets/{ticket}/download', [TicketController::class, 'downloadPdf']);

    // --- LAPORAN KOLEKTIF (REVISI)
    Route::get('/reports/export', [ReportController::class, 'getCollectiveData']);
    Route::get('/reports/export-pdf', [ReportController::class, 'exportCollectivePdf']);
    Route::get('/reports/export-excel', [ReportController::class, 'exportCollectiveExcel']);

    // --- ROUTE KHUSUS PIMPINAN ---
    Route::prefix('pimpinan')->group(function () {
        Route::get('/dashboard', [App\Http\Controllers\Pimpinan\DashboardController::class, 'index']);
        Route::get('/leaves', [App\Http\Controllers\Pimpinan\DashboardController::class, 'getLeaves']);
        Route::get('/dispositions/pending', [App\Http\Controllers\Pimpinan\DashboardController::class, 'getPendingDispositions']);
        Route::get('/dispositions/expired', [App\Http\Controllers\Pimpinan\DashboardController::class, 'getExpiredDispositions']); 
        Route::get('/dispositions/staff/{service_id}', [App\Http\Controllers\Pimpinan\DashboardController::class, 'getAvailableStaffByService']);
        Route::post('/dispositions/assign/{ticket_id}', [App\Http\Controllers\Pimpinan\DashboardController::class, 'assignStaff']);
        Route::post('/dispositions/reject/{ticket_id}', [App\Http\Controllers\Pimpinan\DashboardController::class, 'rejectTicket']);
    });

    // --- ROUTE KHUSUS STAF ---
    Route::prefix('staff')->group(function () {
        Route::get('/leaves', [AttendanceController::class, 'index']);
        Route::post('/leave', [AttendanceController::class, 'submitLeave']);
        Route::put('/leaves/{id}', [AttendanceController::class, 'update']);
        Route::delete('/leaves/{id}', [AttendanceController::class, 'destroy']);
        Route::get('/zoom-links/available', [App\Http\Controllers\Admin\ZoomLinkController::class, 'getAvailableLinks']); 
        
        // ✅ GABUNGKAN MENJADI SATU METHOD YANG BERSIH
        Route::post('/tickets/{ticket}/approve-reject', [TicketController::class, 'processByStaff']);
        
        Route::get('/reminders', function () {
            $staffId = Auth::id();
            return TicketReminderLog::with(['ticket.service'])
                ->where('staff_id', $staffId)
                ->orderBy('sent_at', 'desc')
                ->take(20)
                ->get();
        });
    });

    // --- ROUTE KHUSUS ADMIN ---
    Route::prefix('admin')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/staff-monitoring', [DashboardController::class, 'monitorStaff']);
        
        // ✅ MASTER BIDANGS (DIPINDAH KE ATAS AGAR TIDAK 404)
        Route::get('/bidangs', [UserManagementController::class, 'getMasterBidangs']);
        
        // ✅ MANAJEMEN STAFF (READ & UPDATE OPERASIONAL)
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

        // ✅ UPDATE DATA OPERASIONAL STAFF
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
        });

        Route::get('/dispositions/pending', [DashboardController::class, 'getPendingDispositions']);
        Route::get('/dispositions/expired', [DashboardController::class, 'getExpiredDispositions']);
        Route::post('/dispositions/assign/{ticket_id}', [DashboardController::class, 'assignStaff']);
        Route::post('/dispositions/reject/{ticket_id}', [DashboardController::class, 'rejectTicket']); 
        
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

            // ✅ HAPUS BLOKIR 422, GANTI DENGAN FLAG WARNING
            $warningMessage = null;
            if ($user->active_task_count > 0) {
                $warningMessage = "PERINGATAN: User ini sedang mengerjakan {$user->active_task_count} tugas aktif. Perubahan hak akses saat ini berisiko mengganggu tugas yang sedang berjalan.";
            }

            // Proses Update Role
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
        });

        Route::get('/users', [UserManagementController::class, 'index']);
        Route::get('/users/role-options', [UserManagementController::class, 'getRoleOptions']);
        Route::get('/users/bidang-options', [UserManagementController::class, 'getBidangOptions']);
        Route::put('/users/{id}/role', [UserManagementController::class, 'updateRole']);
        Route::put('/users/{id}/bidang', [UserManagementController::class, 'updateBidang']);
        Route::put('/users/{id}/service-access', [UserManagementController::class, 'updateServiceAccess']);
        Route::put('/users/{id}/bidang', [UserManagementController::class, 'syncUserBidangs']);

        // MANAJEMEN IZIN/CUTI/SAKIT
        Route::get('/leaves', function() {
            return \App\Models\Leave::with('user:id,name,role,attendance_status')->get();
        });
        Route::put('/leaves/{id}/approve', [DashboardController::class, 'approveLeave']);
        Route::put('/leaves/{id}/reject', [DashboardController::class, 'rejectLeave']);

        // MANAJEMEN LINK ZOOM
        Route::get('/zoom-links', [App\Http\Controllers\Admin\ZoomLinkController::class, 'index']);
        Route::post('/zoom-links', [App\Http\Controllers\Admin\ZoomLinkController::class, 'store']);
        Route::put('/zoom-links/{id}', [App\Http\Controllers\Admin\ZoomLinkController::class, 'update']);
        Route::delete('/zoom-links/{id}', [App\Http\Controllers\Admin\ZoomLinkController::class, 'destroy']);
    });
});