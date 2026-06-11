<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Leave;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    // 1. Staf mengajukan izin/cuti
    public function submitLeave(Request $request)
    {
        $request->validate([
            'type'       => 'required|in:Izin,Sakit,Cuti',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'reason'     => 'required|string|max:500',
        ]);

        $leave = Leave::create([
            'user_id'    => Auth::id(),
            'type'       => $request->type,
            'start_date' => $request->start_date,
            'end_date'   => $request->end_date,
            'reason'     => $request->reason,
            'status'     => 'pending',
        ]);

        return response()->json([
            'message' => 'Pengajuan izin berhasil dikirim',
            'data'    => $leave
        ], 201);
    }

    // 2. Staf melihat riwayat izinnya sendiri
    public function index()
    {
        $leaves = Leave::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($leaves);
    }

    // 3. Staf mengedit izin 
    public function update(Request $request, $id)
    {
        $leave = Leave::where('id', $id)->where('user_id', Auth::id())->first();

        if (!$leave) {
            return response()->json(['message' => 'Izin tidak ditemukan'], 404);
        }

        // TAMBAHAN PENGUNCIAN: Cek apakah sudah di-approve Admin
        if ($leave->status !== 'pending') {
            return response()->json(['message' => 'Izin yang sudah diproses tidak bisa diubah lagi.'], 403);
        }

        // PENGUNCIAN TANGGAL: Cek apakah tanggal mulai sudah sampai atau sudah lewat
        if (Carbon::today()->gte($leave->start_date)) {
            return response()->json(['message' => 'Form izin sudah dikunci karena tanggal mulai sudah tiba.'], 403);
        }

        $request->validate([
            'type'       => 'required|in:Izin,Sakit,Cuti',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'reason'     => 'required|string|max:500',
        ]);

        $leave->update([
            'type'       => $request->type,
            'start_date' => $request->start_date,
            'end_date'   => $request->end_date,
            'reason'     => $request->reason,
        ]);

        return response()->json([
            'message' => 'Izin berhasil diperbarui',
            'data'    => $leave
        ]);
    }

    // 4. Staf membatalkan/menghapus izin 
    public function destroy($id)
    {
        $leave = Leave::where('id', $id)->where('user_id', Auth::id())->first();

        if (!$leave) {
            return response()->json(['message' => 'Izin tidak ditemukan'], 404);
        }

        // TAMBAHAN PENGUNCIAN: Cek apakah sudah di-approve Admin
        if ($leave->status !== 'pending') {
            return response()->json(['message' => 'Izin yang sudah diproses tidak bisa dibatalkan lagi.'], 403);
        }

        // PENGUNCIAN TANGGAL: Cek apakah tanggal mulai sudah sampai atau sudah lewat
        if (Carbon::today()->gte($leave->start_date)) {
            return response()->json(['message' => 'Izin tidak bisa dihapus karena tanggal mulai sudah tiba.'], 403);
        }

        $leave->delete();

        return response()->json(['message' => 'Pengajuan izin berhasil dibatalkan']);
    }
}