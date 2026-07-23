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

    if (!\Illuminate\Support\Facades\Auth::attempt([$fieldType => $loginId, 'password' => $request->password])) {
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

    // ✅ TARUH ROUTE LAPORAN DI SINI (DI DALAM GROUP AUTH)
    Route::get('/reports/export-pdf', [App\Http\Controllers\ReportController::class, 'exportPdf']);
    Route::get('/reports/export-excel', [App\Http\Controllers\ReportController::class, 'exportExcel']);

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

    // --- EXPORT EXCEL & WORD ---
    Route::get('tickets/export-excel', [TicketController::class, 'exportExcel']);
    Route::get('tickets/{ticket}/export-word', [TicketController::class, 'exportWord']);
    Route::get('tickets/{ticket}/export-pdf', [TicketController::class, 'downloadPdf']);

    // --- TIKET & TRACKING ---
    Route::apiResource('tickets', TicketController::class);
    Route::match(['put', 'post'], 'tickets/{ticket}/status', [TicketController::class, 'updateStatus']);
    Route::get('/active-schedules', [TicketController::class, 'getActiveSchedules']);
    Route::get('tickets/{id}/resubmit-data', [TicketController::class, 'getResubmitData']); // ✅ TAMBAHKAN INI

    // --- DISKUSI / KOMENTAR ---
    Route::get('tickets/{ticket}/comments', [TicketCommentController::class, 'index']);
    Route::post('tickets/{ticket}/comments', [TicketCommentController::class, 'store']);

    // --- SKM & DOWNLOAD ---
    Route::post('tickets/{ticket}/skm', [TicketController::class, 'submitSKM']);
    Route::get('tickets/{ticket}/download', [TicketController::class, 'downloadDocument']);

    // --- ROUTE KHUSUS PIMPINAN ---
    Route::prefix('pimpinan')->group(function () {
        Route::get('/dashboard', [App\Http\Controllers\Pimpinan\DashboardController::class, 'index']);
        Route::get('/leaves', [App\Http\Controllers\Pimpinan\DashboardController::class, 'getLeaves']);
        Route::get('/dispositions/pending', [App\Http\Controllers\Pimpinan\DashboardController::class, 'getPendingDispositions']);
        Route::get('/dispositions/expired', [App\Http\Controllers\Pimpinan\DashboardController::class, 'getExpiredDispositions']); // ✅ TAMBAHKAN
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
        
        Route::post('/tickets/{ticket}/claim', [TicketController::class, 'claimTicket']);
        Route::post('/tickets/{ticket}/approve-reject', [TicketController::class, 'approveOrRejectTicket']);
        
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
        Route::get('/dispositions/pending', [DashboardController::class, 'getPendingDispositions']);
        Route::get('/dispositions/expired', [DashboardController::class, 'getExpiredDispositions']);
        Route::post('/dispositions/assign/{ticket_id}', [DashboardController::class, 'assignStaff']);
        Route::post('/dispositions/reject/{ticket_id}', [DashboardController::class, 'rejectTicket']); // ✅ TAMBAHKAN INI// ✅ TAMBAHKAN
        
        // ✅ TAMBAHKAN INI (List staf berdasarkan layanan untuk Admin)
        Route::get('/dispositions/staff/{service_id}', [DashboardController::class, 'getAvailableStaffByService']);
        Route::post('/dispositions/assign/{ticket_id}', [DashboardController::class, 'assignStaff']);
        Route::apiResource('services', ServiceController::class);
        
        // MANAJEMEN PENGGUNA
        Route::get('/users', [UserManagementController::class, 'index']);
        Route::get('/users/role-options', [UserManagementController::class, 'getRoleOptions']);
        Route::get('/users/bidang-options', [UserManagementController::class, 'getBidangOptions']);
        Route::put('/users/{id}/role', [UserManagementController::class, 'updateRole']);
        Route::put('/users/{id}/bidang', [UserManagementController::class, 'updateBidang']);
        Route::put('/users/{id}/service-access', [UserManagementController::class, 'updateServiceAccess']);
        Route::get('/bidangs', [UserManagementController::class, 'getMasterBidangs']);
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