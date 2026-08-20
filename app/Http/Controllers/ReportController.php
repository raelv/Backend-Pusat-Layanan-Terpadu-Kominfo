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

    // ✅ DOWNLOAD WORD KOLEKTIF (LAPORAN)
public function exportCollectiveWord(Request $request)
{
    $query = \App\Models\Ticket::with(['service', 'staff', 'requester', 'zoomLink']);
    
    if ($request->filled('start_date') && $request->filled('end_date')) {
        $query->whereBetween('created_at', [$request->start_date, $request->end_date . ' 23:59:59']);
    }

    if ($request->filled('status')) {
        $query->where('status', $request->status);
    }

    $tickets = $query->orderBy('created_at', 'desc')->get();

    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $phpWord->setDefaultFontName('Times New Roman');
    $phpWord->setDefaultFontSize(11);

    $section = $phpWord->addSection(['orientation' => 'landscape', 'marginTop' => 1000]);

    // ======= KOP SURAT =======
    if (file_exists(public_path('images/logo-kominfo.png'))) {
        $section->addImage(public_path('images/logo-kominfo.png'), [
            'width' => 60, 'height' => 60, 'alignment' => 'center'
        ]);
    }

    $section->addText('PEMERINTAH KOTA BONTANG', ['bold' => true, 'size' => 13, 'alignment' => 'center', 'spacing' => 0]);
    $section->addText('DINAS KOMUNIKASI DAN INFORMATIKA', ['bold' => true, 'size' => 12, 'alignment' => 'center', 'spacing' => 0]);
    $section->addText('________________________________________', ['alignment' => 'center', 'size' => 10, 'spacing' => 100]);

    // ======= JUDUL =======
    $section->addText('LAPORAN REKAP LAYANAN', ['bold' => true, 'size' => 13, 'alignment' => 'center', 'spacing' => 200, 'underline' => 'single']);
    
    $startDate = $request->start_date ? \Carbon\Carbon::parse($request->start_date)->format('d F Y') : '-';
    $endDate = $request->end_date ? \Carbon\Carbon::parse($request->end_date)->format('d F Y') : '-';
    $section->addText("Periode: {$startDate} s.d {$endDate}", ['size' => 11, 'alignment' => 'center', 'spacing' => 100]);
    
    $filterStatus = $request->status ? strtoupper($request->status) : 'SEMUA STATUS';
    $section->addText("Status: {$filterStatus} | Total Data: {$tickets->count()}", ['size' => 10, 'alignment' => 'center', 'spacing' => 200]);

    // ======= TABEL DATA =======
    $table = $section->addTable([
        'width' => 100,
        'unit' => 'pct',
        'borderSize' => 1,
        'borderColor' => '000000',
    ]);

    // Header
    $headers = ['No', 'ID Tiket', 'Kategori', 'Pemohon', 'Judul/Perihal', 'Tgl Pengajuan', 'Tgl Pelaksanaan', 'Staff', 'Status'];
    $table->addRow();
    foreach ($headers as $header) {
        $table->addCell(null, ['bgColor' => '1F4E79'])->addText($header, [
            'bold' => true, 'color' => 'FFFFFF', 'size' => 9, 'alignment' => 'center'
        ]);
    }

    // Data rows
    $no = 0;
    foreach ($tickets as $ticket) {
        $no++;
        $judul = $ticket->form_data['namaAplikasi'] ?? $ticket->form_data['topik'] ?? $ticket->form_data['nama_acara'] ?? '-';
        $pelaksanaan = $ticket->schedule_start ? $ticket->schedule_start->format('d/m/Y H:i') . ' s/d ' . $ticket->schedule_end->format('H:i') : '-';
        $pemohon = ($ticket->requester->name ?? '-') . ' (' . ($ticket->requester->bidang ?? 'OPD') . ')';
        $staffName = $ticket->staff ? $ticket->staff->name : '-';
        $statusLabel = strtoupper($ticket->status);

        $table->addRow();
        $table->addCell(500)->addText($no, ['size' => 9, 'alignment' => 'center']);
        $table->addCell(1000)->addText('#' . $ticket->ticket_number, ['size' => 9]);
        $table->addCell(1500)->addText(strtoupper($ticket->service->category ?? '-'), ['size' => 9]);
        $table->addCell(2500)->addText($pemohon, ['size' => 9]);
        $table->addCell(2500)->addText($judul, ['size' => 9]);
        $table->addCell(1500)->addText($ticket->created_at->format('d/m/Y'), ['size' => 9, 'alignment' => 'center']);
        $table->addCell(2000)->addText($pelaksanaan, ['size' => 9]);
        $table->addCell(1500)->addText($staffName, ['size' => 9]);
        $table->addCell(1200)->addText($statusLabel, ['size' => 9, 'alignment' => 'center']);
    }

    // ======= TANDA TANGAN =======
    $section->addText('', ['spacing' => 300]);
    $ttdTable = $section->addTable(['width' => 30, 'unit' => 'pct', 'alignment' => 'right']);
    $ttdTable->addRow();
    $ttdCell = $ttdTable->addCell(3000);
    $ttdCell->addText('Bontang, ' . \Carbon\Carbon::now()->translatedFormat('d F Y'), ['alignment' => 'center', 'size' => 10]);
    $ttdCell->addText('Kepala Dinas Kominfo,', ['alignment' => 'center', 'size' => 10]);
    $ttdCell->addText('', ['spacing' => 600]);
    $ttdCell->addText('________________________', ['alignment' => 'center', 'size' => 10]);
    $ttdCell->addText('NIP. ................................', ['alignment' => 'center', 'size' => 9]);

    $tempFilePath = storage_path('Laporan_Rekap_Layanan_Kominfo.docx');
    $phpWord->save($tempFilePath, 'Word2007');

    return response()->download($tempFilePath)->deleteFileAfterSend(true);
}
}