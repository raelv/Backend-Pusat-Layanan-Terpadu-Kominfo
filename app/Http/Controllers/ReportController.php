<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Exports\RekapLayananExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
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

        $tickets = $this->getData($request);
        
        return Excel::download(new RekapLayananExport($tickets), 'Laporan_Rekap_Layanan.xlsx');
    }

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
}