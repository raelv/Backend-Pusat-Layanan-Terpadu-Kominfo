<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $query = User::whereIn('role', ['staff', 'admin']);

        if ($request->has('role') && in_array($request->role, ['staff', 'admin'])) {
            $query->where('role', $request->role);
        }

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('nip', 'ILIKE', "%{$search}%")
                  ->orWhere('email', 'ILIKE', "%{$search}%");
            });
        }

        return $query->orderBy('name', 'asc')->paginate(10);
    }

    public function getRoleOptions()
    {
        return response()->json([
            'options' => [
                ['value' => 'staff', 'label' => 'Staff'],
                ['value' => 'admin', 'label' => 'Admin']
            ]
        ]);
    }

    public function getBidangOptions()
    {
        return response()->json([
            'options' => [
                'Layanan IT',
                'Layanan Zoom',
                'Layanan Command Center'
            ]
        ]);
    }

    public function getMasterBidangs()
    {
        return \App\Models\Bidang::select('id', 'nama')->get();
    }

    /**
     * Validasi Perubahan Role (Dengan cross-check kelengkapan data)
     */
    public function updateRole(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan'], 404);
        }

        if (!in_array($user->role, ['staff', 'admin'])) {
            return response()->json(['message' => 'Role Pimpinan dan OPD tidak dapat diubah melalui halaman ini.'], 403);
        }

        $request->validate([
            'role' => 'required|in:staff,admin'
        ], [
            'role.required' => 'Role wajib dipilih.'
        ]);
        
        if ($user->active_task_count > 0) {
            return response()->json([
                'message' => 'Staff sedang mengerjakan tugas sehingga perubahan Role atau Bidang tidak dapat dilakukan. Selesaikan seluruh tugas aktif terlebih dahulu.'
            ], 422);
        }

        // ✅ VALIDASI PRD: Cek kelengkapan data sebelum mengubah role
        if ($request->role === 'staff') {
            $errors = [];
            if (empty($user->bidang) || is_null($user->bidang)) {
                $errors[] = 'Bidang / Instansi wajib dipilih terlebih dahulu.';
            }
            if (empty($user->service_access) || is_null($user->service_access) || count($user->service_access) === 0) {
                $errors[] = 'Hak Akses Layanan wajib dipilih terlebih dahulu.';
            }
            
            if (!empty($errors)) {
                return response()->json(['message' => implode(' ', $errors)], 422);
            }
        }

        $oldRole = $user->role;
        $user->role = $request->role;

        // Jika FE mengirim bidang bersamaan
        if ($request->has('bidang')) {
            $allowedBidang = ['Layanan IT', 'Layanan Zoom', 'Layanan Command Center'];
            if (in_array($request->bidang, $allowedBidang)) {
                $user->bidang = $request->bidang;
            }
        }

        $user->save();

        return response()->json([
            'message' => "Berhasil mengubah data staff.",
            'data' => $user->refresh()->load('bidangs')
        ]);
    }

    /**
     * Validasi Perubahan Bidang Instansi (Dengan cross-check hak akses)
     */
    public function updateBidang(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan'], 404);
        }

        if ($user->role !== 'staff') {
            return response()->json(['message' => 'Perubahan bidang hanya berlaku untuk akun dengan Role Staff.'], 403);
        }

        $nilaiBidang = $request->input('bidang') 
                    ?? $request->input('staff_category') 
                    ?? $request->input('staff_division');

        if (!$nilaiBidang) {
            return response()->json(['message' => 'Field bidang tidak ditemukan dalam request.'], 422);
        }

        $allowedBidang = ['Layanan IT', 'Layanan Zoom', 'Layanan Command Center'];
        if (!in_array($nilaiBidang, $allowedBidang)) {
            return response()->json(['message' => 'Bidang yang dipilih tidak valid. Pilihan: Layanan IT, Layanan Zoom, Layanan Command Center.'], 422);
        }

        if ($user->active_task_count > 0) {
            return response()->json([
                'message' => 'Staff sedang mengerjakan tugas sehingga perubahan Role atau Bidang tidak dapat dilakukan. Selesaikan seluruh tugas aktif terlebih dahulu.'
            ], 422);
        }

        // ✅ VALIDASI PRD: Cek kelengkapan data Hak Akses
        if (empty($user->service_access) || is_null($user->service_access) || count($user->service_access) === 0) {
            return response()->json(['message' => 'Hak Akses Layanan wajib dipilih terlebih dahulu.'], 422);
        }

        $user->update(['bidang' => $nilaiBidang]);

        return response()->json([
            'message' => "Berhasil mengubah Bidang menjadi {$user->bidang}.",
            'data' => $user->refresh()->load('bidangs')
        ]);
    }

    /**
     * Validasi Perubahan Hak Akses Layanan (Dengan cross-check bidang)
     */
    public function updateServiceAccess(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan'], 404);
        }

        if ($user->role !== 'staff') {
            return response()->json(['message' => 'Hak akses layanan hanya untuk role Staff.'], 403);
        }

        $request->validate([
            'access_list' => 'required|array',
            'access_list.*' => 'in:it,zoom,command_center'
        ]);

        if ($user->active_task_count > 0) {
            return response()->json([
                'message' => 'Staff sedang mengerjakan tugas sehingga Hak Akses tidak dapat diubah. Selesaikan seluruh tugas aktif terlebih dahulu.'
            ], 422);
        }

        // ✅ VALIDASI PRD: Cek kelengkapan data Bidang
        if (empty($user->bidang) || is_null($user->bidang)) {
            return response()->json(['message' => 'Bidang / Instansi wajib dipilih terlebih dahulu.'], 422);
        }

        $user->update([
            'service_access' => $request->access_list
        ]);

        return response()->json([
            'message' => "Berhasil memperbarui hak akses layanan.",
            'data' => $user->refresh()->load('bidangs')
        ]);
    }

    /**
     * Sinkronisasi Multi-Bidang (Dengan cross-check hak akses)
     */
    public function syncUserBidangs(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan'], 404);
        }

        $request->validate([
            'bidang_ids' => 'required|array',
            'bidang_ids.*' => 'exists:bidangs,id'
        ]);

        if ($user->active_task_count > 0) {
            return response()->json([
                'message' => 'Staff sedang mengerjakan tugas sehingga Bidang tidak dapat diubah. Selesaikan seluruh tugas aktif terlebih dahulu.'
            ], 422);
        }

        // ✅ VALIDASI PRD: Cek kelengkapan Hak Akses
        if (empty($user->service_access) || is_null($user->service_access) || count($user->service_access) === 0) {
            return response()->json(['message' => 'Hak Akses Layanan wajib dipilih terlebih dahulu.'], 422);
        }

        $user->bidangs()->sync($request->bidang_ids);

        return response()->json([
            'message' => 'Berhasil memperbarui bidang staff.',
            'data' => $user->load('bidangs')
        ]);
    }
}