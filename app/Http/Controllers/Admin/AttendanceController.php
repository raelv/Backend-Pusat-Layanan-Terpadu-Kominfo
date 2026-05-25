<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function updateStaffStatus(Request $request, User $staff)
    {
        $request->validate(['status' => 'in:Masuk,Cuti,Izin,Sakit']);

        // Admin kembali punya akses penuh untuk atur ulang staf.
        $staff->update(['attendance_status' => $request->status]);
        
        return response()->json(['message' => 'Status kehadiran berhasil diubah.']);
    }
}