<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Staff\AttendanceController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\TicketCommentController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// 1. LOGIN SSO (Mengecek domain resmi)
Route::post('/login', function (Request $request) {
    $request->validate([
        'email' => 'required|email',
        'password' => 'required' 
    ]);

    $email = $request->email;

    // VALIDASI DOMAIN
    if (!str_ends_with($email, '@bontangkota.go.id')) {
        return response()->json(['message' => 'Akses Ditolak. Hanya email @bontangkota.go.id.'], 403);
    }

    // CARI USER DI DATABASE
    $user = \App\Models\User::where('email', $email)->first();

    if ($user) {
        // JIKA USER SUDAH ADA
        if (\Illuminate\Support\Facades\Auth::attempt(['email' => $email, 'password' => $request->password])) {
            $token = $user->createToken('api-token')->plainTextToken;
            return response()->json([
                'message' => 'Login Berhasil',
                'user' => $user,
                'token' => $token
            ]);
        } else {
            return response()->json(['message' => 'Password Salah!'], 401);
        }
    } else {
        // JIKA USER BELUM ADA: AUTO REGISTER SEBAGAI OPD
        $user = \App\Models\User::create([
            'name' => explode('@', $request->email)[0],
            'email' => $request->email, // <- DITAMBAHKAN BIAR GAK ERROR NULL
            'password' => bcrypt('opd123'),
            'role' => 'opd',
            'attendance_status' => 'Masuk'
        ]);

        \Illuminate\Support\Facades\Auth::login($user);
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Akun baru berhasil dibuat. Login Berhasil sebagai OPD.',
            'user' => $user,
            'token' => $token
        ]);
    }
});

// PROTECTED ROUTES (Butuh Token)
Route::middleware('auth:sanctum')->group(function () {
    
    // --- TIKET & TRACKING (Akses Umum) ---
    Route::apiResource('tickets', TicketController::class);
    Route::post('tickets/{ticket}/status', [TicketController::class, 'updateStatus']);

    // --- DISKUSI / KOMENTAR ---
    Route::get('tickets/{ticket}/comments', [TicketCommentController::class, 'index']);
    Route::post('tickets/{ticket}/comments', [TicketCommentController::class, 'store']);

    // --- SKM & DOWNLOAD ---
    Route::post('tickets/{ticket}/skm', [TicketController::class, 'submitSKM']);
    Route::get('tickets/{ticket}/download', [TicketController::class, 'downloadDocument']);

    // --- ROUTE KHUSUS STAFF ---
    Route::prefix('staff')->group(function () {
        Route::post('/leave', [AttendanceController::class, 'submitLeave']); 
        Route::post('/tickets/{ticket}/claim', [TicketController::class, 'claimTicket']);
    });

    // --- ROUTE KHUSUS ADMIN ---
    Route::prefix('admin')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/staff-monitoring', [DashboardController::class, 'monitorStaff']);
        Route::apiResource('services', ServiceController::class);
    });
});