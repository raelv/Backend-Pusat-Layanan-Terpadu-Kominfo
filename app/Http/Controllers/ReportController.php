<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Exports\RekapLayananExport;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function exportPdf(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'status'     => 'nullable|in:pending_approval,pending,queued,assigned,approved_admin,in_progress,completed,rejected,cancelled,expired,needs_reschedule,overdue_schedule',
            'service_id' => 'nullable|exists:services,id',
        ]);

        // PDF tetap pakai getData() karena butuh Collection untuk load ke Blade
        $tickets = $this->getData($request);
        
        $data = [
            'tickets' => $tickets,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'filter_status' => $request->status,
            'filter_service' => $request->service_id ? \App\Models\Service::find($request->service_id)->name : 'Semua Layanan',
        ];

        $pdf = Pdf::loadView('pdf.rekap-layanan', $data)->setPaper('A4', 'landscape');
        return $pdf->stream('Laporan_Rekap_Layanan.pdf');
    }

    public function exportExcel(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'status'     => 'nullable|in:pending,queued,assigned,in_progress,completed,rejected,cancelled,expired',
            'service_id' => 'nullable|exists:services,id',
        ]);

        // ✅ UBAH: Excel pakai getQuery() biar hemat RAM
        $query = $this->getQuery($request);
        
        return Excel::download(new RekapLayananExport($query), 'Laporan_Rekap_Layanan.xlsx');
    }

    // ✅ TAMBAHKAN: Query Builder khusus untuk Excel (Tanpa ->get())
    private function getQuery($request)
    {
        $query = Ticket::with(['service', 'staff', 'requester'])
            ->whereBetween('created_at', [$request->start_date . ' 00:00:00', $request->end_date . ' 23:59:59']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('service_id')) {
            $query->where('service_id', $request->service_id);
        }

        return $query->orderBy('created_at', 'desc');
    }

    // ✅ GET DATA: Khusus untuk PDF (Mengembalikan Collection)
    private function getData($request)
    {
        $query = Ticket::with(['service', 'staff', 'requester'])
            ->whereBetween('created_at', [$request->start_date . ' 00:00:00', $request->end_date . ' 23:59:59']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('service_id')) {
            $query->where('service_id', $request->service_id);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    // ✅ Preview Data JSON untuk Front-End
    public function getCollectiveData(Request $request)
    {
        $query = \App\Models\Ticket::with(['service', 'staff', 'requester', 'zoomLink']);

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$request->start_date, $request->end_date . ' 23:59:59']);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $tickets = $query->orderBy('created_at', 'desc')->get();

        $data = $tickets->map(function ($ticket) {
            return [
                'id' => $ticket->id,
                'ticket_number' => 'Ticket #' . $ticket->ticket_number,
                'service_name' => $ticket->service->name ?? null,
                'category' => $ticket->service->category ?? null,
                'requester_name' => $ticket->requester->name ?? null,
                'requester_instansi' => $ticket->requester->bidang ?? ($ticket->requester->name ?? null),
                'created_at' => $ticket->created_at->toDateTimeString(),
                'schedule_start' => $ticket->schedule_start ? $ticket->schedule_start->toDateTimeString() : null,
                'schedule_end' => $ticket->schedule_end ? $ticket->schedule_end->toDateTimeString() : null,
                'status' => $ticket->status,
                'staff_name' => $ticket->staff->name ?? null,
                'staff_nip' => $ticket->staff->nip ?? null,
                'zoom_link' => $ticket->zoomLink ? $ticket->zoomLink->link : null,
                'rejection_reason' => $ticket->rejection_reason ?? null
            ];
        });

        return response()->json([
            'message' => 'Data laporan berhasil diambil',
            'data' => $data,
            'meta' => [
                'total' => $data->count(),
                'filtered_by_date' => $request->filled('start_date'),
                'filtered_by_status' => $request->filled('status')
            ]
        ]);
    }

    // ✅ DOWNLOAD PDF KOLEKTIF
    public function exportCollectivePdf(Request $request)
    {
        $query = \App\Models\Ticket::with(['service', 'staff', 'requester', 'zoomLink']);
        
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$request->start_date, $request->end_date . ' 23:59:59']);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // PDF tetap wajib pakai ->get()
        $tickets = $query->orderBy('created_at', 'desc')->get();
        
        $data = [
            'tickets' => $tickets,
            'printed_at' => now()->translatedFormat('d F Y, H:i'),
            'start_date' => $request->start_date ? \Carbon\Carbon::parse($request->start_date) : now()->subMonths(3),
            'end_date' => $request->end_date ? \Carbon\Carbon::parse($request->end_date) : now(),
            'filter_status' => $request->status ?? 'Semua Status',
            'filter_service' => 'Semua Layanan'
        ];
        
        return Pdf::loadView('pdf.rekap-layanan', $data)
            ->setPaper('A4', 'landscape')
            ->download('Laporan_Seluruh_Layanan_Kominfo.pdf');
    }

    // ✅ DOWNLOAD EXCEL KOLEKTIF
    public function exportCollectiveExcel(Request $request)
    {
        $query = \App\Models\Ticket::with(['service', 'staff', 'requester', 'zoomLink']);
        
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$request->start_date, $request->end_date . ' 23:59:59']);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $query->orderBy('created_at', 'desc');
        
        // ✅ UBAH: Hapus ->cursor(), Langsung kirim $query-nya ke Export
        return Excel::download(
            new RekapLayananExport($query), 
            'Laporan_Seluruh_Kominfo.xlsx'
        );
    }
}