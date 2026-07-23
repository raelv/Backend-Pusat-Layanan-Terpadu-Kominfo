<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class RekapLayananExport implements FromCollection, WithHeadings, WithStyles
{
    protected $tickets;

    public function __construct($tickets)
    {
        $this->tickets = $tickets;
    }

    public function collection()
    {
        $no = 0;
        return $this->tickets->map(function($ticket) use (&$no) {
            $no++;
            
            $judul = $ticket->form_data['namaAplikasi'] ?? $ticket->form_data['topik'] ?? $ticket->form_data['nama_acara'] ?? $ticket->form_data['nama_kegiatan'] ?? '-';
            
            $pelaksanaan = '-';
            if ($ticket->schedule_start) {
                $pelaksanaan = $ticket->schedule_start->format('d M Y, H:i') . ' s/d ' . $ticket->schedule_end->format('H:i');
            } elseif ($ticket->due_date) {
                $pelaksanaan = $ticket->due_date->format('d M Y');
            }

            $pemohon = trim(($ticket->requester->name ?? '-') . ' (' . ($ticket->requester->bidang ?? 'OPD') . ')');
            $staffName = $ticket->staff ? $ticket->staff->name : '-';

            $statusLabel = strtoupper($ticket->status);
            if ($ticket->status === 'assigned' || $ticket->status === 'in_progress') $statusLabel = 'DIPROSES';
            if ($ticket->status === 'pending' || $ticket->status === 'queued') $statusLabel = 'MENUNGGU';

            return [
                $no,
                '#' . $ticket->ticket_number,
                $ticket->service->category ?? '-',
                $pemohon,
                $judul,
                $ticket->created_at->format('d M Y'),
                $pelaksanaan,
                $staffName,
                $statusLabel
            ];
        });
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

    public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
    {
        return [
            1    => ['font' => ['bold' => true]],
        ];
    }
}