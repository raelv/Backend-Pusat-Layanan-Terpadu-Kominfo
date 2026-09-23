<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class RekapLayananExport implements FromQuery, WithHeadings, WithMapping, WithStyles, WithColumnWidths
{
    protected $query;
    protected $no = 0;

    public function __construct($query)
    {
        $this->query = $query;
    }

    public function query()
    {
        return $this->query;
    }

    public function map($ticket): array
    {
        $this->no++;

        $judul = $ticket->report_title;

        $pelaksanaan = '-';
        if ($ticket->schedule_start) {
            $pelaksanaan = $ticket->schedule_start->format('d/m/Y H:i') . ' s/d ' . ($ticket->schedule_end ? $ticket->schedule_end->format('H:i') : '-');
        } elseif ($ticket->due_date) {
            $pelaksanaan = $ticket->due_date->format('d/m/Y');
        }

        $pemohon = trim(($ticket->requester->name ?? '-') . ' (' . ($ticket->requester->bidang ?? 'OPD') . ')');
        $staffName = $ticket->report_staff_name;

        $statusLabel = strtoupper($ticket->status);
        if (in_array($ticket->status, ['assigned', 'in_progress', 'approved_admin'])) $statusLabel = 'DIPROSES';
        if (in_array($ticket->status, ['pending', 'queued'])) $statusLabel = 'MENUNGGU';
        if ($ticket->status === 'completed') $statusLabel = 'SELESAI';
        if ($ticket->status === 'rejected') $statusLabel = 'DITOLAK';
        if ($ticket->status === 'cancelled') $statusLabel = 'DIBATALKAN';
        if ($ticket->status === 'expired') $statusLabel = 'KADALUARSA';
        if ($ticket->status === 'needs_reschedule') $statusLabel = 'JADWAL ULANG';

        return [
            $this->no,
            '#' . $ticket->ticket_number,
            strtoupper($ticket->service->category ?? '-'),
            $pemohon,
            $judul,
            $ticket->created_at->format('d/m/Y'),
            $pelaksanaan,
            $staffName,
            $statusLabel
        ];
    }

    public function headings(): array
    {
        return [
            'No',
            'ID Tiket',
            'Kategori Layanan',
            'Instansi / Pemohon',
            'Judul / Perihal',
            'Tgl Pengajuan',
            'Tgl Pelaksanaan / Estimasi',
            'Staff / Teknisi',
            'Status Akhir'
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 5,
            'B' => 12,
            'C' => 18,
            'D' => 30,
            'E' => 30,
            'F' => 14,
            'G' => 25,
            'H' => 22,
            'I' => 15,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:I1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 11,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1F4E79'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->setAutoFilter('A1:I1');

        $sheet->getRowDimension(1)->setRowHeight(25);

        $highestRow = $sheet->getHighestDataRow();

        $sheet->getStyle('A1:I' . $highestRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        $sheet->getStyle('A2:I' . $highestRow)->applyFromArray([
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        $sheet->getStyle('A2:A' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('I2:I' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        for ($i = 2; $i <= $highestRow; $i++) {
            if ($i % 2 == 0) {
                $sheet->getStyle('A' . $i . ':I' . $i)->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'D9E2F3'],
                    ],
                ]);
            }
        }
    }
}