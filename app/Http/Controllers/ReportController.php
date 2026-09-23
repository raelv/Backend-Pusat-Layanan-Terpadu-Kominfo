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
            'judul' => $ticket->report_title,
            'service_name' => $ticket->service->name ?? null,
            'category' => $ticket->service->category ?? null,
            'requester_name' => $ticket->requester->name ?? null,
            'requester_instansi' => $ticket->requester->bidang ?? ($ticket->requester->name ?? null),
            'created_at' => $ticket->created_at->toDateTimeString(),
            'schedule_start' => $ticket->schedule_start ? $ticket->schedule_start->toDateTimeString() : null,
            'schedule_end' => $ticket->schedule_end ? $ticket->schedule_end->toDateTimeString() : null,
            'status' => $ticket->status,
            'staff_name' => $ticket->report_staff_name,
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
    $startedAt = microtime(true);

    $startDate = $request->filled('start_date')
        ? \Carbon\Carbon::parse($request->start_date)->startOfDay()
        : null;

    $endDate = $request->filled('end_date')
        ? \Carbon\Carbon::parse($request->end_date)->endOfDay()
        : null;

    if ($startDate && $endDate && $startDate->gt($endDate)) {
        return response()->json(['message' => 'Tanggal mulai tidak boleh melebihi tanggal selesai.'], 422);
    }

    $tickets = \App\Models\Ticket::with(['service', 'staff', 'requester', 'zoomLink'])
        ->when($startDate && $endDate, fn ($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
        ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
        ->orderBy('created_at', 'desc')
        ->get();

    $queryMs = round((microtime(true) - $startedAt) * 1000);

    \Illuminate\Support\Facades\Log::info('REPORT_PDF_QUERY_DONE', [
        'rows' => $tickets->count(),
        'start' => $startDate?->toDateString(),
        'end' => $endDate?->toDateString(),
        'query_ms' => $queryMs,
        'memory_mb' => round(memory_get_usage(true) / 1048576),
    ]);

    $data = [
        'tickets' => $tickets,
        'printed_at' => now()->translatedFormat('d F Y, H:i'),
        'start_date' => $startDate,
        'end_date' => $endDate,
        'filter_status' => $request->status ?? 'Semua Status',
        'filter_service' => 'Semua Layanan',
    ];

    try {
        set_time_limit(300);
        ini_set('memory_limit', '1024M');

        $renderStart = microtime(true);

        $pdf = Pdf::loadView('pdf.rekap-layanan', $data)
            ->setPaper('A4', 'landscape')
            ->setOption(['isRemoteEnabled' => false]);

        $output = $pdf->output();

        \Illuminate\Support\Facades\Log::info('REPORT_PDF_RENDER_DONE', [
            'rows' => $tickets->count(),
            'render_ms' => round((microtime(true) - $renderStart) * 1000),
            'total_ms' => round((microtime(true) - $startedAt) * 1000),
            'pdf_bytes' => strlen($output),
            'memory_mb' => round(memory_get_usage(true) / 1048576),
        ]);

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Laporan_Seluruh_Layanan_Kominfo.pdf"',
            'Content-Length' => strlen($output),
        ]);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('REPORT_PDF_FAILED', [
            'rows' => $tickets->count(),
            'error' => $e->getMessage(),
            'class' => get_class($e),
            'line' => $e->getLine(),
            'total_ms' => round((microtime(true) - $startedAt) * 1000),
        ]);

        return response()->json([
            'message' => 'Gagal membuat PDF laporan.',
            'total_data' => $tickets->count(),
            'hint' => 'Coba persempit rentang tanggal lalu ulangi, atau gunakan tombol Excel.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

    // ✅ DOWNLOAD EXCEL KOLEKTIF
public function exportCollectiveExcel(Request $request)
{
    $startedAt = microtime(true);

    $startDate = $request->filled('start_date')
        ? \Carbon\Carbon::parse($request->start_date)->startOfDay()
        : null;

    $endDate = $request->filled('end_date')
        ? \Carbon\Carbon::parse($request->end_date)->endOfDay()
        : null;

    if ($startDate && $endDate && $startDate->gt($endDate)) {
        return response()->json(['message' => 'Tanggal mulai tidak boleh melebihi tanggal selesai.'], 422);
    }

    $query = \App\Models\Ticket::with(['service', 'staff', 'requester', 'zoomLink'])
        ->when($startDate && $endDate, fn ($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
        ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
        ->orderBy('created_at', 'desc');

    $rows = (clone $query)->count();

    \Illuminate\Support\Facades\Log::info('REPORT_EXCEL_QUERY_DONE', [
        'rows' => $rows,
        'start' => $startDate?->toDateString(),
        'end' => $endDate?->toDateString(),
        'query_ms' => round((microtime(true) - $startedAt) * 1000),
    ]);

    try {
        set_time_limit(300);
        ini_set('memory_limit', '1024M');

        return Excel::download(
            new RekapLayananExport($query),
            'Laporan_Seluruh_Kominfo.xlsx'
        );
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('REPORT_EXCEL_FAILED', [
            'rows' => $rows,
            'error' => $e->getMessage(),
            'class' => get_class($e),
            'line' => $e->getLine(),
            'total_ms' => round((microtime(true) - $startedAt) * 1000),
        ]);

        return response()->json([
            'message' => 'Gagal membuat laporan Excel.',
            'total_data' => $rows,
            'hint' => 'Coba persempit rentang tanggal lalu ulangi.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

    // ✅ DOWNLOAD WORD KOLEKTIF (LAPORAN)
public function exportCollectiveWord(Request $request)
{
    $startedAt = microtime(true);

    $startDate = $request->filled('start_date')
        ? \Carbon\Carbon::parse($request->start_date)->startOfDay()
        : null;

    $endDate = $request->filled('end_date')
        ? \Carbon\Carbon::parse($request->end_date)->endOfDay()
        : null;

    if ($startDate && $endDate && $startDate->gt($endDate)) {
        return response()->json(['message' => 'Tanggal mulai tidak boleh melebihi tanggal selesai.'], 422);
    }

    $tickets = \App\Models\Ticket::with([
        'service',
        'staff',
        'requester',
        'zoomLink'
    ])
        ->when($startDate && $endDate, fn ($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
        ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
        ->orderBy('created_at', 'desc')
        ->get();

    \Illuminate\Support\Facades\Log::info('REPORT_WORD_QUERY_DONE', [
        'rows' => $tickets->count(),
        'start' => $startDate?->toDateString(),
        'end' => $endDate?->toDateString(),
        'query_ms' => round((microtime(true) - $startedAt) * 1000),
        'memory_mb' => round(memory_get_usage(true) / 1048576),
    ]);

    try {
        set_time_limit(300);
        ini_set('memory_limit', '1024M');

        $phpWord = new \PhpOffice\PhpWord\PhpWord();

        $phpWord->setDefaultFontName('Times New Roman');
        $phpWord->setDefaultFontSize(10);

        $section = $phpWord->addSection([
            'orientation' => 'landscape',
            'pageSizeW' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(29.7),
            'pageSizeH' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(21),
            'marginTop' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.5),
            'marginRight' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.5),
            'marginBottom' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.5),
            'marginLeft' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.5),
        ]);

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

        $section->addText(
            '',
            [],
            [
                'borderBottomSize' => 10,
                'borderBottomColor' => '000000',
                'spaceAfter' => 120,
            ]
        );

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

        $periodeStart = $startDate
            ? $startDate->locale('id')->translatedFormat('d F Y')
            : 'Awal Data';

        $periodeEnd = $endDate
            ? $endDate->locale('id')->translatedFormat('d F Y')
            : 'Data Terbaru';

        $section->addText(
            "Periode: {$periodeStart} s.d {$periodeEnd}",
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

        $no = 0;

        foreach ($tickets as $ticket) {
            $no++;

            $judul = $ticket->report_title;

            if ($ticket->schedule_start) {
                $pelaksanaan = $ticket->schedule_start->format('d/m/Y H:i');

                if ($ticket->schedule_end) {
                    $pelaksanaan .= ' s/d ' . $ticket->schedule_end->format('H:i');
                }
            } elseif ($ticket->due_date) {
                $pelaksanaan = $ticket->due_date->format('d/m/Y');
            } else {
                $pelaksanaan = '-';
            }

            $pemohon =
                ($ticket->requester->name ?? '-') .
                ' (' .
                ($ticket->requester->bidang ?? 'OPD') .
                ')';

            $staffName = $ticket->report_staff_name;

            $statusLabel = strtoupper($ticket->status);

            if (in_array($ticket->status, ['assigned', 'in_progress', 'approved_admin'])) {
                $statusLabel = 'DIPROSES';
            }

            if (in_array($ticket->status, ['pending', 'queued'])) {
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

            $table->addRow();

            $table->addCell(500, ['valign' => 'center'])->addText(
                (string) $no,
                $fontSmall,
                $paragraphCenter
            );

            $table->addCell(1000, ['valign' => 'center'])->addText(
                '#' . $ticket->ticket_number,
                $fontSmall,
                $paragraphCenter
            );

            $table->addCell(1500, ['valign' => 'center'])->addText(
                strtoupper($ticket->service->category ?? '-'),
                $fontSmall,
                $paragraphCenter
            );

            $table->addCell(2400, ['valign' => 'center'])->addText(
                $pemohon,
                $fontSmall,
                $paragraphLeft
            );

            $table->addCell(2500, ['valign' => 'center'])->addText(
                $judul,
                $fontSmall,
                $paragraphLeft
            );

            $table->addCell(1500, ['valign' => 'center'])->addText(
                $ticket->created_at->format('d/m/Y'),
                $fontSmall,
                $paragraphCenter
            );

            $table->addCell(2000, ['valign' => 'center'])->addText(
                $pelaksanaan,
                $fontSmall,
                $paragraphCenter
            );

            $table->addCell(1600, ['valign' => 'center'])->addText(
                $staffName,
                $fontSmall,
                $paragraphLeft
            );

            $table->addCell(1200, ['valign' => 'center'])->addText(
                $statusLabel,
                $fontSmall,
                $paragraphCenter
            );
        }

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

        $section->addText('', [], ['spaceAfter' => 250]);

        $ttdTable = $section->addTable([
            'width' => 30,
            'unit' => 'pct',
            'alignment' => 'right',
        ]);

        $ttdTable->addRow();

        $ttdCell = $ttdTable->addCell(3000, ['valign' => 'top']);

        $ttdCell->addText(
            'Bontang, ' . \Carbon\Carbon::now()->locale('id')->translatedFormat('d F Y'),
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

        $ttdCell->addText('', [], ['spaceAfter' => 600]);

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

        $tempFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'Laporan_Rekap_Layanan_Kominfo_' . uniqid('', true) . '.docx';

        $phpWord->save($tempFilePath, 'Word2007');

        \Illuminate\Support\Facades\Log::info('REPORT_WORD_RENDER_DONE', [
            'rows' => $tickets->count(),
            'total_ms' => round((microtime(true) - $startedAt) * 1000),
            'file_bytes' => filesize($tempFilePath),
            'memory_mb' => round(memory_get_usage(true) / 1048576),
        ]);

        return response()
            ->download($tempFilePath)
            ->deleteFileAfterSend(true);

    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('REPORT_WORD_FAILED', [
            'rows' => $tickets->count(),
            'error' => $e->getMessage(),
            'class' => get_class($e),
            'line' => $e->getLine(),
            'total_ms' => round((microtime(true) - $startedAt) * 1000),
        ]);

        return response()->json([
            'message' => 'Gagal membuat laporan Word.',
            'total_data' => $tickets->count(),
            'hint' => 'Coba persempit rentang tanggal lalu ulangi, atau gunakan tombol Excel.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
}