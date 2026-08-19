<?php

namespace App\Exports;

use App\Models\Ticket;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// ✅ GANTI FromCollection Jadi FromQuery & TAMBAHKAN WithMapping
class RekapLayananExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    protected $query;

    // ✅ TERIMA QUERY BUILDER, BUKAN DATA LAKSANA
    public function __construct($query)
    {
        $this->query = $query;
    }

    // ✅ KEMBALIKAN QUERY BUILDER (MAATWEBSITE YANG EKSEKUSI)
    public function query()
    {
        return $this->query;
    }

    // ✅ MAPPING 1 BARIS SAAT ITU JUGA (HEMAT RAM)
    public function map($ticket): array
    {
        static $no = 0; // Static agar nomor urut terus bertambah saat di-chunk
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

    public function styles(Worksheet $sheet)
    {
        return [
            1    => ['font' => ['bold' => true]],
        ];
    }
}