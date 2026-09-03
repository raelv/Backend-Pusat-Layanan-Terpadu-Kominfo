<?php

namespace App\Exports;

use App\Models\Ticket;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class BuktiLayananExport implements FromCollection, WithHeadings, WithStyles, WithColumnWidths, WithTitle
{
    protected $ticket;

    public function __construct(Ticket $ticket)
    {
        $this->ticket = $ticket;
    }

    // ✅ NAMA SHEET BAHASA INDONESIA
    public function title(): string
    {
        return 'Bukti Layanan';
    }

    private function formatWa($wa)
    {
        if (empty($wa)) return '-';
        if (strpos($wa, '08') === 0) return $wa;
        if (strpos($wa, '+62') === 0) return '0' . substr($wa, 3);
        if (strpos($wa, '62') === 0) return '0' . substr($wa, 2);
        return $wa;
    }

    // ✅ NAMA HARI BAHASA INDONESIA
    private function formatHariIndo($date)
    {
        $hari = [
            'Sunday'    => 'Minggu',
            'Monday'    => 'Senin',
            'Tuesday'   => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday'  => 'Kamis',
            'Friday'    => 'Jumat',
            'Saturday'  => 'Sabtu',
        ];
        
        $hariEn = $date->format('l');
        return $hari[$hariEn] ?? $hariEn;
    }

    // ✅ NAMA BULAN BAHASA INDONESIA
    private function formatBulanIndo($date)
    {
        $bulan = [
            'January'   => 'Januari',
            'February'  => 'Februari',
            'March'     => 'Maret',
            'April'     => 'April',
            'May'       => 'Mei',
            'June'      => 'Juni',
            'July'      => 'Juli',
            'August'    => 'Agustus',
            'September' => 'September',
            'October'   => 'Oktober',
            'November'  => 'November',
            'December'  => 'Desember',
        ];
        
        $bulanEn = $date->format('F');
        return $bulan[$bulanEn] ?? $bulanEn;
    }

    // ✅ FORMAT TANGGAL LENGKAP INDONESIA
    private function formatTanggalIndo($date)
    {
        $hari = $this->formatHariIndo($date);
        $tanggal = $date->format('d');
        $bulan = $this->formatBulanIndo($date);
        $tahun = $date->format('Y');
        
        return "{$hari}, {$tanggal} {$bulan} {$tahun}";
    }

    public function collection()
    {
        $ticket = $this->ticket;
        $wa = $this->formatWa($ticket->form_data['wa'] ?? null);
        
        $rows = [
            ['BUKTI PENERIMAAN LAYANAN', '', ''],
            ['Nomor', '', $ticket->id . '/KOMINFO/' . date('m/Y', strtotime($ticket->created_at))],
            ['', '', ''],
            ['Nama Pemohon', '', $ticket->requester->name ?? 'N/A'],
            ['Email / OPD', '', $ticket->requester->email ?? '-'],
            ['Jenis Layanan', '', $ticket->service->name ?? 'N/A'],
            ['Hari / Tanggal', '', $this->formatTanggalIndo(\Carbon\Carbon::parse($ticket->created_at))],
        ];
        
        if ($ticket->schedule_start) {
            $mulai = $this->formatBulanIndo(\Carbon\Carbon::parse($ticket->schedule_start)) 
                     ? \Carbon\Carbon::parse($ticket->schedule_start)->format('d F Y, H:i') 
                     : \Carbon\Carbon::parse($ticket->schedule_start)->format('d/m/Y, H:i');
            
            // Replace nama bulan ke Indonesia
            $mulai = str_replace(
                ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
                ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'],
                $mulai
            );
            
            $rows[] = ['Jadwal Layanan', '', $mulai . ' s.d ' . \Carbon\Carbon::parse($ticket->schedule_end)->format('H:i') . ' WITA'];
        }
        
        $rows[] = ['Pelaksana Staf', '', $ticket->staff->name ?? 'Belum Ditugaskan'];
        $rows[] = ['Status', '', strtoupper($ticket->status)];
        $rows[] = ['', '', ''];
        $rows[] = ['DETAIL PERMOHONAN', '', ''];
        
        if ($ticket->form_data && count($ticket->form_data) > 0) {
            foreach ($ticket->form_data as $key => $value) {
                if ($key === 'wa') continue;
                $label = ucfirst(str_replace('_', ' ', $key));
                $val = is_array($value) ? implode(', ', $value) : $value;
                $rows[] = [$label, '', $val];
            }
        }
        
        $rows[] = ['', '', ''];
        $rows[] = ['Nomor WhatsApp', '', $wa];

        return collect($rows);
    }

    public function headings(): array
    {
        return ['Keterangan', '', 'Detail'];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 30,
            'B' => 3,
            'C' => 55,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Judul (baris 1)
        $sheet->getStyle('A1:C1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'name' => 'Times New Roman'],
            'alignment' => ['horizontal' => 'center'],
        ]);

        // Nomor (baris 2)
        $sheet->getStyle('A2:C2')->applyFromArray([
            'font' => ['size' => 11, 'name' => 'Times New Roman'],
            'alignment' => ['horizontal' => 'center'],
        ]);

        // Styling per baris
        for ($i = 1; $i <= $sheet->getHighestRow(); $i++) {
            $val = $sheet->getCell('A' . $i)->getValue();
            
            // Label bold (kecuali baris kosong, judul, dan detail header)
            if ($val && !in_array($val, ['', 'BUKTI PENERIMAAN LAYANAN', 'DETAIL PERMOHONAN'])) {
                $sheet->getStyle('A' . $i)->applyFromArray([
                    'font' => ['bold' => true, 'name' => 'Times New Roman', 'size' => 11],
                ]);
            }

            // Background biru muda untuk judul
            if ($val === 'BUKTI PENERIMAAN LAYANAN') {
                $sheet->getStyle('A1:C1')->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'D9E2F3'],
                    ],
                ]);
            }

            // Background hijau muda untuk detail header
            if ($val === 'DETAIL PERMOHONAN') {
                $sheet->getStyle('A' . $i . ':C' . $i)->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11, 'name' => 'Times New Roman'],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'E2EFDA'],
                    ],
                ]);
            }

            // Background kuning muda untuk WA
            if ($val === 'Nomor WhatsApp') {
                $sheet->getStyle('A' . $i . ':C' . $i)->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11, 'name' => 'Times New Roman'],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'FFF9C4'],
                    ],
                ]);
            }
        }

        // Sembunyikan kolom B (separator)
        $sheet->getColumnDimension('B')->setWidth(0);
        for ($i = 1; $i <= $sheet->getHighestRow(); $i++) {
            $sheet->getStyle('B' . $i)->applyFromArray([
                'font' => ['size' => 1],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FFFFFF'],
                ],
            ]);
        }
    }
}