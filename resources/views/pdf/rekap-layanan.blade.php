<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { 
            margin: 20mm 15mm 25mm 20mm; 
            size: A4 landscape; 
        }
        
        body { 
            font-family: 'Times New Roman', Times, serif; 
            font-size: 10pt; 
            color: #000; 
        }

        /* KOP SURAT */
        .kop {
            text-align: center;
            border-bottom: 3px double #000;
            padding-bottom: 8px;
            margin-bottom: 15px;
        }
        .kop img { 
            width: 55px; 
            height: auto; 
            margin-bottom: 3px; 
        }
        .kop h1 { 
            margin: 0; 
            font-size: 13pt; 
            letter-spacing: 1.5px; 
        }
        .kop h2 { 
            margin: 2px 0; 
            font-size: 11pt; 
        }
        .kop p { 
            margin: 1px 0; 
            font-size: 8pt; 
        }

        /* JUDUL */
        .judul {
            text-align: center;
            margin-bottom: 12px;
        }
        .judul h3 {
            margin: 0;
            font-size: 12pt;
            text-decoration: underline;
        }

        /* INFO FILTER */
        .info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 9pt;
        }
        .info span {
            background: #f0f0f0;
            padding: 3px 8px;
            border-radius: 3px;
        }

        /* TABEL */
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 15px;
        }
        th, td { 
            border: 1px solid #333; 
            padding: 5px 6px; 
            text-align: left; 
            font-size: 9pt;
        }
        thead th {
            background-color: #1F4E79;
            color: #fff;
            font-weight: bold;
            text-align: center;
            font-size: 8.5pt;
        }
        tbody tr:nth-child(even) {
            background-color: #E8F0FE;
        }
        .text-center { text-align: center; }
        .text-bold { font-weight: bold; }

        /* FOOTER */
        .footer {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
            font-size: 8pt;
            color: #666;
        }

        /* TANDA TANGAN */
        .ttd-container {
            display: flex;
            justify-content: flex-end;
            margin-top: 30px;
        }
        .ttd {
            width: 200px;
            text-align: center;
            font-size: 10pt;
        }
        .ttd .space { height: 60px; }
        .ttd .nama { 
            font-weight: bold; 
            text-decoration: underline; 
        }
        .ttd .nip { font-size: 9pt; }

        /* EMPTY STATE */
        .empty {
            text-align: center;
            padding: 30px;
            font-style: italic;
            color: #666;
        }
    </style>
</head>
<body>

    <!-- KOP SURAT -->
    <div class="kop">
        @if(file_exists(public_path('images/logo-kominfo.png')))
            <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/logo-kominfo.png'))) }}" alt="Logo">
        @endif
        <h1>PEMERINTAH KOTA BONTANG</h1>
        <h2>DINAS KOMUNIKASI DAN INFORMATIKA</h2>
        <p>Jl. Brigjen Katamso No. 1, Bontang Utara, Kota Bontang, Kalimantan Timur</p>
        <p>Telp: (0548) 22222 | Website: kominfo.bontangkota.go.id</p>
    </div>

    <!-- JUDUL LAPORAN -->
    <div class="judul">
        <h3>LAPORAN REKAPITULASI LAYANAN</h3>
    </div>

    <!-- INFO FILTER -->
    <div class="info">
        <span>📅 Periode: {{ \Carbon\Carbon::parse($start_date)->format('d F Y') }} s/d {{ \Carbon\Carbon::parse($end_date)->format('d F Y') }}</span>
        <span>📂 Layanan: {{ $filter_service }}</span>
        <span>📊 Status: {{ $filter_status ?? 'Semua Status' }}</span>
    </div>

    <!-- TABEL DATA -->
    <table>
        <thead>
            <tr>
                <th width="3%">No</th>
                <th width="8%">ID Tiket</th>
                <th width="12%">Kategori</th>
                <th width="20%">Instansi / Pemohon</th>
                <th width="20%">Judul / Perihal</th>
                <th width="10%">Tgl Pengajuan</th>
                <th width="14%">Tgl Pelaksanaan</th>
                <th width="13%">Staff / Teknisi</th>
                <th width="10%">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($tickets as $index => $ticket)
                <?php
                    $judul = $ticket->form_data['namaAplikasi'] ?? $ticket->form_data['topik'] ?? $ticket->form_data['nama_acara'] ?? $ticket->form_data['nama_kegiatan'] ?? '-';
                    
                    $pelaksanaan = '-';
                    if ($ticket->schedule_start) {
                        $pelaksanaan = $ticket->schedule_start->format('d/m/Y H:i') . ' s/d ' . $ticket->schedule_end->format('H:i');
                    } elseif ($ticket->due_date) {
                        $pelaksanaan = $ticket->due_date->format('d/m/Y');
                    }

                    $pemohon = trim(($ticket->requester->name ?? '-') . ' (' . ($ticket->requester->bidang ?? 'OPD') . ')');
                    $staffName = $ticket->staff ? $ticket->staff->name : '-';

                    $statusLabel = strtoupper($ticket->status);
                    if (in_array($ticket->status, ['assigned', 'in_progress', 'approved_admin'])) $statusLabel = 'DIPROSES';
                    if (in_array($ticket->status, ['pending', 'queued'])) $statusLabel = 'MENUNGGU';
                    if ($ticket->status === 'completed') $statusLabel = 'SELESAI';
                    if ($ticket->status === 'rejected') $statusLabel = 'DITOLAK';
                    if ($ticket->status === 'cancelled') $statusLabel = 'DIBATALKAN';
                    if ($ticket->status === 'expired') $statusLabel = 'KADALUARSA';
                    if ($ticket->status === 'needs_reschedule') $statusLabel = 'JADWAL ULANG';
                ?>
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td class="text-center">#{{ $ticket->ticket_number }}</td>
                    <td class="text-center">{{ strtoupper($ticket->service->category ?? '-') }}</td>
                    <td>{{ $pemohon }}</td>
                    <td>{{ $judul }}</td>
                    <td class="text-center">{{ $ticket->created_at->format('d/m/Y') }}</td>
                    <td class="text-center">{{ $pelaksanaan }}</td>
                    <td>{{ $staffName }}</td>
                    <td class="text-center text-bold">{{ $statusLabel }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="empty">Tidak ada data pada periode dan filter yang dipilih.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <!-- FOOTER INFO -->
    <div class="footer">
        <span>Total Data: {{ $tickets->count() }} tiket</span>
        <span>Dicetak: {{ $printed_at ?? now()->translatedFormat('d F Y, H:i') }} WITA</span>
    </div>

    <!-- TANDA TANGAN -->
    <div class="ttd-container">
        <div class="ttd">
            <p>Bontang, {{ \Carbon\Carbon::now()->translatedFormat('d F Y') }}</p>
            <p>Kepala Dinas Kominfo,</p>
            <div class="space"></div>
            <p class="nama">________________________</p>
            <p class="nip">NIP. ................................</p>
        </div>
    </div>

</body>
</html>