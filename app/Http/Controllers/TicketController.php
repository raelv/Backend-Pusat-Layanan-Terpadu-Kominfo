<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\TicketsExport;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpWord\PhpWord;

class TicketController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        if ($user->role === 'admin') {
            return Ticket::with(['service', 'staff', 'requester', 'comments.user'])->get();
        }
        if ($user->role === 'staff') {
            return Ticket::where('assigned_staff_id', $user->id)
                ->orWhere(function($query) {
                    $query->whereNull('assigned_staff_id')->whereIn('status', ['pending', 'queued']);
                })->with(['service', 'staff', 'requester', 'comments.user'])->get();
        }
        return Ticket::where('user_id', $user->id)->with(['service', 'staff', 'requester', 'comments.user'])->get();
    }

    public function show($id)
    {
        $ticket = Ticket::with(['service', 'staff', 'requester', 'comments.user'])->find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        return response()->json($ticket);
    }

    public function store(Request $request)
    {
        $request->validate([
            'service_id' => 'required|exists:services,id',
            'form_data' => 'required', 
            'schedule_start' => 'nullable|date',
        ]);
        $formData = $request->form_data;
        if (is_string($formData)) $formData = json_decode($formData, true);

        $ticket = Ticket::create([
            'service_id' => $request->service_id,
            'user_id' => Auth::id(), 
            'form_data' => $formData,
            'status' => 'pending', 
            'schedule_start' => $request->schedule_start,
        ]);
        return response()->json(['message' => 'Tiket berhasil dibuat', 'data' => $ticket->load(['service', 'requester'])], 201);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:pending,queued,approved_admin,assigned,in_progress,completed,rejected,cancelled']);
        $user = Auth::user();
        $ticket = Ticket::find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        if ($user->role === 'admin') return response()->json(['message' => 'Akses ditolak.'], 403);
        if ($user->role === 'staff' && $ticket->assigned_staff_id !== $user->id) return response()->json(['message' => 'Akses ditolak.'], 403);
        $ticket->status = $request->status;
        $ticket->save();
        return response()->json(['message' => 'Status tiket berhasil diperbarui', 'data' => $ticket]);
    }

    public function claimTicket(Request $request, $id)
    {
        $user = Auth::user();
        $ticket = Ticket::find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        if (!is_null($ticket->assigned_staff_id)) return response()->json(['message' => 'Tiket sudah diambil staf lain.'], 403);
        $ticket->assigned_staff_id = $user->id;
        $ticket->status = 'assigned';
        $ticket->save();
        return response()->json(['message' => 'Tiket berhasil diambil', 'data' => $ticket->load(['service', 'staff', 'requester'])]);
    }

    public function previewPdf($id)
    {
        $ticket = Ticket::with(['service', 'staff', 'requester'])->find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        return Pdf::loadView('pdf.bukti-layanan', compact('ticket'))->setPaper('A4', 'portrait')->stream('Bukti_Layanan_Tiket_'.$ticket->id.'.pdf');
    }

    public function downloadPdf($id)
    {
        $ticket = Ticket::with(['service', 'staff', 'requester'])->find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        return Pdf::loadView('pdf.bukti-layanan', compact('ticket'))->setPaper('A4', 'portrait')->download('Bukti_Layanan_Tiket_'.$ticket->id.'.pdf');
    }

    public function exportExcel()
    {
        return Excel::download(new TicketsExport, 'Rekap_Data_Tiket_Kominfo.xlsx');
    }

    public function exportWord($id)
    {
        $ticket = Ticket::with(['service', 'staff', 'requester'])->find($id);
        if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);

        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('BUKTI PENERIMAAN LAYANAN', ['bold' => true, 'size' => 16, 'alignment' => 'center']);
        $section->addText('Nomor: ' . $ticket->id . '/KOMINFO/' . date('m/Y', strtotime($ticket->created_at)), ['size' => 11, 'alignment' => 'center']);
        $section->addTextBreak(1);
        $section->addText('Hari / Tanggal    : ' . \Carbon\Carbon::parse($ticket->created_at)->translatedFormat('l, d F Y'));
        $section->addText('Pemohon (OPD)     : ' . ($ticket->requester->name ?? 'N/A'));
        $section->addText('Jenis Layanan      : ' . ($ticket->service->name ?? 'N/A'));
        $section->addText('Status                : ' . strtoupper($ticket->status));
        $section->addText('Pelaksana Staf    : ' . ($ticket->staff->name ?? 'Belum Ditugaskan'));
        
        $fileName = 'Bukti_Layanan_Tiket_' . $ticket->id . '.docx';
        $tempFilePath = storage_path($fileName);
        $phpWord->save($tempFilePath, 'Word2007');
        return response()->download($tempFilePath)->deleteFileAfterSend(true);
    }
}