<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ZoomLink;
use Illuminate\Http\Request;

class ZoomLinkController extends Controller
{
    // 1. Tampilkan Semua Link
    public function index()
    {
        $links = ZoomLink::orderBy('created_at', 'desc')->get();
        return response()->json($links);
    }
    // 2. Tambah Link Baru
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'url'  => 'required|url|max:255',
        ]);

        // Cek duplikat
        if (ZoomLink::where('link', $request->url)->exists()) {
            return response()->json(['message' => 'Link Zoom ini sudah terdaftar di sistem.'], 409);
        }

        $link = ZoomLink::create([
            'title' => $request->name,
            'link'  => $request->url,
            'status' => 'available'
        ]);

        return response()->json(['message' => 'Link Zoom berhasil ditambahkan', 'data' => $link], 201);
    }

    // 3. Edit Link
    public function update(Request $request, $id)
    {
        $zoomLink = ZoomLink::find($id);
        if (!$zoomLink) return response()->json(['message' => 'Link tidak ditemukan'], 404);

        if ($zoomLink->status === 'in_use') {
            return response()->json(['message' => 'Tidak bisa mengedit link yang sedang dipakai.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'url'  => 'required|url|max:255',
        ]);

        // Cek duplikat (kecuali dirinya sendiri)
        if (ZoomLink::where('link', $request->url)->where('id', '!=', $id)->exists()) {
            return response()->json(['message' => 'Link Zoom ini sudah terdaftar di sistem.'], 409);
        }

        $zoomLink->update([
            'title' => $request->name,
            'link'  => $request->url,
        ]);

        return response()->json(['message' => 'Link Zoom berhasil diperbarui', 'data' => $zoomLink]);
    }

    // 4. Hapus Link
    public function destroy($id)
    {
        $zoomLink = ZoomLink::find($id);
        if (!$zoomLink) return response()->json(['message' => 'Link tidak ditemukan'], 404);

        // Cegah hapus jika sedang dipakai
        if ($zoomLink->status === 'in_use') {
            return response()->json(['message' => 'Tidak bisa menghapus link yang sedang dipakai.'], 403);
        }

        $zoomLink->delete();
        return response()->json(['message' => 'Link Zoom berhasil dihapus']);
    }

    // 5. Ambil Link Tersedia (Dipakai oleh Staff)
    public function getAvailableLinks()
    {
        $links = ZoomLink::where('status', 'available')->get();
        return response()->json($links);
    }
}