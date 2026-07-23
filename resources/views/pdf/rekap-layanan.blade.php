<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #000; padding: 6px 8px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; text-align: center; }
        .header { text-align: center; margin-bottom: 10px; }
        .header h2 { margin: 0; }
        .header p { margin: 2px 0; font-size: 11px; }
        .info { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 11px; }
        .text-center { text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h2>LAPORAN REKAPITULASI LAYANAN</h2>
        <p>DINAS KOMUNIKASI DAN INFORMATIKA KOTA BONTANG</p>
    </div>

    <div class="info">
        <span>Periode: {{ \Carbon\Carbon::parse($start_date)->format('d M Y') }} s/d {{ \Carbon\Carbon::parse($end_date)->format('d M Y') }}</span>
        <span>Filter Layanan: {{ $filter_service }}</span>
        <span>Status: {{ $filter_status ?? 'Semua Status' }}</span>
    </div>

    <table>
        <thead>
            <tr>
                <th width="3%">No</th>
                <th width="8%">ID Tiket</th>
                <th width="15%">Kategori Layanan</th>
                <th width="18%">Instansi / Pemohon</th>
                <th width="18%">Judul / Perihal</th>
                <th width="12%">Tgl Pengajuan</th>
                <th width="14%">Tgl Pelaksanaan / Estimasi</th>
                <th width="12%">Staff / Teknisi</th>
                <th width="10%">Status Akhir</th>
            </tr>
        </thead>
        <tbody>
            @forelse($tickets as $index => $ticket)
                <?php
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
                ?>
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td class="text-center">#{{ $ticket->ticket_number }}</td>
                    <td>{{ $ticket->service->category ?? '-' }}</td>
                    <td>{{ $pemohon }}</td>
                    <td>{{ $judul }}</td>
                    <td class="text-center">{{ $ticket->created_at->format('d M Y') }}</td>
                    <td>{{ $pelaksanaan }}</td>
                    <td>{{ $staffName }}</td>
                    <td class="text-center"><b>{{ $statusLabel }}</b></td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center">Tidak ada data laporan pada periode dan filter yang dipilih.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>