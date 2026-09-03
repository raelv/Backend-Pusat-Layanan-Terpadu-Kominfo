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
                'ticket_number' => 'Tiket #' . $ticket->ticket_number,
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
    // =========================================================
    // QUERY DATA
    // =========================================================

    $query = \App\Models\Ticket::with([
        'service',
        'staff',
        'requester',
        'zoomLink'
    ]);

    if ($request->filled('start_date') && $request->filled('end_date')) {
        $query->whereBetween(
            'created_at',
            [
                $request->start_date,
                $request->end_date . ' 23:59:59'
            ]
        );
    }

    if ($request->filled('status')) {
        $query->where(
            'status',
            $request->status
        );
    }

    $tickets = $query
        ->orderBy('created_at', 'desc')
        ->get();

    // =========================================================
    // PHPWORD
    // =========================================================

    $phpWord = new \PhpOffice\PhpWord\PhpWord();

    $phpWord->setDefaultFontName('Times New Roman');
    $phpWord->setDefaultFontSize(10);

    // =========================================================
    // SECTION LANDSCAPE
    // =========================================================

    $section = $phpWord->addSection([
        'orientation' => 'landscape',

        'pageSizeW' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(29.7),
        'pageSizeH' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(21),

        'marginTop' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.5),
        'marginRight' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.5),
        'marginBottom' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.5),
        'marginLeft' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.5),
    ]);

    // =========================================================
    // STYLE
    // =========================================================

    $fontNormal = [
        'name' => 'Times New Roman',
        'size' => 10,
    ];

    $fontSmall = [
        'name' => 'Times New Roman',
        'size' => 9,
    ];

    $fontHeader = [
        'name' => 'Times New Roman',
        'size' => 9,
        'bold' => true,
        'color' => 'FFFFFF',
    ];

    $paragraphCenter = [
        'alignment' => 'center',
        'spaceBefore' => 0,
        'spaceAfter' => 0,
    ];

    $paragraphLeft = [
        'alignment' => 'left',
        'spaceBefore' => 0,
        'spaceAfter' => 0,
    ];

    // =========================================================
    // KOP SURAT
    // =========================================================

    if (file_exists(public_path('images/logo-kominfo.png'))) {

        $section->addImage(
            public_path('images/logo-kominfo.png'),
            [
                'width' => 55,
                'height' => 55,
                'alignment' => 'center',
            ]
        );
    }

    $section->addText(
        'PEMERINTAH KOTA BONTANG',
        [
            'name' => 'Times New Roman',
            'size' => 13,
            'bold' => true,
        ],
        [
            'alignment' => 'center',
            'spaceBefore' => 0,
            'spaceAfter' => 0,
        ]
    );

    $section->addText(
        'DINAS KOMUNIKASI DAN INFORMATIKA',
        [
            'name' => 'Times New Roman',
            'size' => 11,
            'bold' => true,
        ],
        [
            'alignment' => 'center',
            'spaceBefore' => 0,
            'spaceAfter' => 0,
        ]
    );

    $section->addText(
        'Jl. Brigjen Katamso No. 1, Bontang Utara, Kota Bontang',
        [
            'name' => 'Times New Roman',
            'size' => 8,
        ],
        [
            'alignment' => 'center',
            'spaceBefore' => 40,
            'spaceAfter' => 0,
        ]
    );

    $section->addText(
        'Telp: (0548) 22222 | Website: kominfo.bontangkota.go.id',
        [
            'name' => 'Times New Roman',
            'size' => 8,
        ],
        [
            'alignment' => 'center',
            'spaceBefore' => 0,
            'spaceAfter' => 80,
        ]
    );

    // Garis kop
    $section->addText(
        '',
        [],
        [
            'borderBottomSize' => 10,
            'borderBottomColor' => '000000',
            'spaceAfter' => 120,
        ]
    );

    // =========================================================
    // JUDUL
    // =========================================================

    $section->addText(
        'LAPORAN REKAPITULASI LAYANAN',
        [
            'name' => 'Times New Roman',
            'size' => 13,
            'bold' => true,
            'underline' => 'single',
        ],
        [
            'alignment' => 'center',
            'spaceBefore' => 80,
            'spaceAfter' => 80,
        ]
    );

    // =========================================================
    // FILTER
    // =========================================================

    $startDate = $request->start_date
        ? \Carbon\Carbon::parse($request->start_date)
            ->translatedFormat('d F Y')
        : '-';

    $endDate = $request->end_date
        ? \Carbon\Carbon::parse($request->end_date)
            ->translatedFormat('d F Y')
        : '-';

    $section->addText(
        "Periode: {$startDate} s.d {$endDate}",
        [
            'name' => 'Times New Roman',
            'size' => 9,
        ],
        [
            'alignment' => 'center',
            'spaceBefore' => 0,
            'spaceAfter' => 30,
        ]
    );

    $filterStatus = $request->status
        ? strtoupper($request->status)
        : 'SEMUA STATUS';

    $section->addText(
        "Status: {$filterStatus} | Total Data: {$tickets->count()}",
        [
            'name' => 'Times New Roman',
            'size' => 9,
        ],
        [
            'alignment' => 'center',
            'spaceBefore' => 0,
            'spaceAfter' => 120,
        ]
    );

    // =========================================================
    // TABEL DATA
    // =========================================================

    $table = $section->addTable([
        'borderSize' => 6,
        'borderColor' => '000000',
        'cellMarginTop' => 50,
        'cellMarginBottom' => 50,
        'cellMarginLeft' => 60,
        'cellMarginRight' => 60,
        'width' => 100,
        'unit' => 'pct',
    ]);

    // =========================================================
    // HEADER
    // =========================================================

    $headers = [
        'No',
        'ID Tiket',
        'Kategori',
        'Pemohon',
        'Judul/Perihal',
        'Tgl Pengajuan',
        'Tgl Pelaksanaan',
        'Staff',
        'Status',
    ];

    $headerWidths = [
        500,
        1000,
        1500,
        2400,
        2500,
        1500,
        2000,
        1600,
        1200,
    ];

    $table->addRow();

    foreach ($headers as $index => $header) {

        $table->addCell(
            $headerWidths[$index],
            [
                'bgColor' => '1F4E79',
                'valign' => 'center',
            ]
        )->addText(
            $header,
            $fontHeader,
            $paragraphCenter
        );
    }

    // =========================================================
    // DATA
    // =========================================================

    $no = 0;

    foreach ($tickets as $ticket) {

        $no++;

        // Judul / Perihal
        $judul =
            $ticket->form_data['namaAplikasi']
            ?? $ticket->form_data['topik']
            ?? $ticket->form_data['nama_acara']
            ?? $ticket->form_data['nama_kegiatan']
            ?? '-';

        // Pelaksanaan
        if ($ticket->schedule_start) {

            $pelaksanaan =
                $ticket->schedule_start->format('d/m/Y H:i');

            if ($ticket->schedule_end) {
                $pelaksanaan .=
                    ' s/d ' .
                    $ticket->schedule_end->format('H:i');
            }

        } elseif ($ticket->due_date) {

            $pelaksanaan =
                $ticket->due_date->format('d/m/Y');

        } else {

            $pelaksanaan = '-';
        }

        // Pemohon
        $pemohon =
            ($ticket->requester->name ?? '-') .
            ' (' .
            ($ticket->requester->bidang ?? 'OPD') .
            ')';

        // Staff
        $staffName =
            $ticket->staff
                ? $ticket->staff->name
                : '-';

        // Status
        $statusLabel = strtoupper($ticket->status);

        if (in_array($ticket->status, [
            'assigned',
            'in_progress',
            'approved_admin'
        ])) {
            $statusLabel = 'DIPROSES';
        }

        if (in_array($ticket->status, [
            'pending',
            'queued'
        ])) {
            $statusLabel = 'MENUNGGU';
        }

        if ($ticket->status === 'completed') {
            $statusLabel = 'SELESAI';
        }

        if ($ticket->status === 'rejected') {
            $statusLabel = 'DITOLAK';
        }

        if ($ticket->status === 'cancelled') {
            $statusLabel = 'DIBATALKAN';
        }

        if ($ticket->status === 'expired') {
            $statusLabel = 'KADALUARSA';
        }

        if ($ticket->status === 'needs_reschedule') {
            $statusLabel = 'JADWAL ULANG';
        }

        // =====================================================
        // ROW
        // =====================================================

        $table->addRow();

        $table->addCell(
            500,
            ['valign' => 'center']
        )->addText(
            (string) $no,
            $fontSmall,
            $paragraphCenter
        );

        $table->addCell(
            1000,
            ['valign' => 'center']
        )->addText(
            '#' . $ticket->ticket_number,
            $fontSmall,
            $paragraphCenter
        );

        $table->addCell(
            1500,
            ['valign' => 'center']
        )->addText(
            strtoupper($ticket->service->category ?? '-'),
            $fontSmall,
            $paragraphCenter
        );

        $table->addCell(
            2400,
            ['valign' => 'center']
        )->addText(
            $pemohon,
            $fontSmall,
            $paragraphLeft
        );

        $table->addCell(
            2500,
            ['valign' => 'center']
        )->addText(
            $judul,
            $fontSmall,
            $paragraphLeft
        );

        $table->addCell(
            1500,
            ['valign' => 'center']
        )->addText(
            $ticket->created_at->format('d/m/Y'),
            $fontSmall,
            $paragraphCenter
        );

        $table->addCell(
            2000,
            ['valign' => 'center']
        )->addText(
            $pelaksanaan,
            $fontSmall,
            $paragraphCenter
        );

        $table->addCell(
            1600,
            ['valign' => 'center']
        )->addText(
            $staffName,
            $fontSmall,
            $paragraphLeft
        );

        $table->addCell(
            1200,
            ['valign' => 'center']
        )->addText(
            $statusLabel,
            $fontSmall,
            $paragraphCenter
        );
    }

    // =========================================================
    // JIKA DATA KOSONG
    // =========================================================

    if ($tickets->count() === 0) {

        $section->addText(
            'Tidak ada data pada periode yang dipilih.',
            [
                'name' => 'Times New Roman',
                'size' => 10,
                'italic' => true,
            ],
            [
                'alignment' => 'center',
                'spaceBefore' => 100,
                'spaceAfter' => 100,
            ]
        );
    }

    // =========================================================
    // TANDA TANGAN
    // =========================================================

    $section->addText(
        '',
        [],
        [
            'spaceAfter' => 250,
        ]
    );

    $ttdTable = $section->addTable([
        'width' => 30,
        'unit' => 'pct',
        'alignment' => 'right',
    ]);

    $ttdTable->addRow();

    $ttdCell = $ttdTable->addCell(
        3000,
        [
            'valign' => 'top',
        ]
    );

    $ttdCell->addText(
        'Bontang, ' .
        \Carbon\Carbon::now()->translatedFormat('d F Y'),
        [
            'name' => 'Times New Roman',
            'size' => 10,
        ],
        [
            'alignment' => 'center',
            'spaceAfter' => 60,
        ]
    );

    $ttdCell->addText(
        'Kepala Dinas Kominfo,',
        [
            'name' => 'Times New Roman',
            'size' => 10,
        ],
        [
            'alignment' => 'center',
            'spaceAfter' => 0,
        ]
    );

    $ttdCell->addText(
        '',
        [],
        [
            'spaceAfter' => 600,
        ]
    );

    $ttdCell->addText(
        '________________________',
        [
            'name' => 'Times New Roman',
            'size' => 10,
        ],
        [
            'alignment' => 'center',
            'spaceAfter' => 20,
        ]
    );

    $ttdCell->addText(
        'NIP. ................................',
        [
            'name' => 'Times New Roman',
            'size' => 9,
        ],
        [
            'alignment' => 'center',
        ]
    );

    // =========================================================
    // SIMPAN & DOWNLOAD
    // =========================================================

 $tempFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'Laporan_Rekap_Layanan_Kominfo.docx';

 $phpWord->save(
    $tempFilePath,
    'Word2007'
);

return response()
    ->download($tempFilePath)
    ->deleteFileAfterSend(true);
}
}