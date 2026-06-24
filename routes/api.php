<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Staff\AttendanceController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\TicketCommentController;
use App\Models\TicketReminderLog;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

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
    return response()->json([
        'message' => 'Login Berhasil', 
        'user' => $user, 
        'token' => $token
    ]);
});

// PUBLIC ROUTE UNTUK PREVIEW PDF
Route::get('tickets/{ticket}/preview-pdf', [TicketController::class, 'previewPdf']);

// PROTECTED ROUTES (Butuh Token)
Route::middleware('auth:sanctum')->group(function () {
    
    // --- CEK JAM OPERASIONAL & AKSES CEPAT LAYANAN ---
    Route::get('/operational-status', function () {
        // ✅ FIX TIMEZONE WITA
        $now = \Carbon\Carbon::now('Asia/Makassar');
        $startTime = \Carbon\Carbon::createFromTime(7, 30, 0, 'Asia/Makassar');
        $endTime = \Carbon\Carbon::createFromTime(22, 0, 0, 'Asia/Makassar');

        $isOperational = $now->gte($startTime) && $now->lte($endTime);

        return response()->json([
            'is_operational' => $isOperational,
            'message' => $isOperational ? 'Layanan aktif.' : 'Pengajuan layanan sedang ditutup. Jam operasional adalah 07:30 - 22:00 WIB.'
        ]);
    });

    Route::get('/quick-services', function () {
        // ✅ FIX TIMEZONE WITA
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

    // --- DAFTAR AKUN (UNTUK DROPDOWN) ---
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

    // ✅ ENDPOINT BARU: JADWAL LAYANAN AKTIF
    Route::get('/active-schedules', [TicketController::class, 'getActiveSchedules']);

    // --- DISKUSI / KOMENTAR ---
    Route::get('tickets/{ticket}/comments', [TicketCommentController::class, 'index']);
    Route::post('tickets/{ticket}/comments', [TicketCommentController::class, 'store']);

    // --- SKM & DOWNLOAD ---
    Route::post('tickets/{ticket}/skm', [TicketController::class, 'submitSKM']);
    Route::get('tickets/{ticket}/download', [TicketController::class, 'downloadDocument']);

    // --- ROUTE KHUSUS STAF ---
    Route::prefix('staff')->group(function () {
        Route::get('/leaves', [AttendanceController::class, 'index']);
        Route::post('/leave', [AttendanceController::class, 'submitLeave']);
        Route::put('/leaves/{id}', [AttendanceController::class, 'update']);
        Route::delete('/leaves/{id}', [AttendanceController::class, 'destroy']); 
        
        Route::post('/tickets/{ticket}/claim', [TicketController::class, 'claimTicket']);
        Route::post('/tickets/{ticket}/approve-reject', [TicketController::class, 'approveOrRejectTicket']);
        
        // ✅ ENDPOINT BARU: REMINDER DEADLINE STAFF
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
        Route::apiResource('services', ServiceController::class);
        Route::get('/leaves', function() {
            return \App\Models\Leave::with('user:id,name,role,attendance_status')->get();
        });
    });
});