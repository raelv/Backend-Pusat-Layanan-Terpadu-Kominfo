<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Bukti Penerimaan Layanan</title>

    <style>
        @page {
            size: A4;
            margin: 2cm 2.5cm 2cm 2.5cm;
        }

        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 12pt;
            color: #000;
            line-height: 1.5;
            margin: 0;
            padding: 0;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        /* ========== KOP SURAT ========== */
        .kop-table {
            width: 100%;
            border-bottom: 3.5px double #000;
            padding-bottom: 6px;
            margin-bottom: 20pt;
        }

        .logo-kiri {
            width: 18%;
            text-align: left;
            vertical-align: middle;
        }

        .logo-kanan {
            width: 18%;
            text-align: right;
            vertical-align: middle;
        }

        .kop-isi {
            width: 64%;
            text-align: center;
            vertical-align: middle;
        }

        .kop-instansi {
            font-size: 15pt;
            font-weight: bold;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
            text-transform: uppercase;
        }

        .kop-dinas {
            font-size: 13pt;
            font-weight: bold;
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .kop-alamat {
            font-size: 9pt;
            line-height: 1.2;
        }

        .kop-logo {
            width: 75px;
            height: 75px;
            object-fit: contain;
        }

        /* ========== JUDUL SURAT ========== */
        .judul-section {
            text-align: center;
            margin-bottom: 20pt;
        }

        .judul-text {
            font-size: 13pt;
            font-weight: bold;
            text-decoration: underline;
            margin-bottom: 4px;
        }

        .nomor-text {
            font-size: 11pt;
        }

        /* ========== PARAGRAF PEMBUKA ========== */
        .pembuka {
            text-align: justify;
            text-justify: inter-word;
            text-indent: 1cm;
            margin-bottom: 12pt;
            line-height: 1.5;
        }

        /* ========== TABEL DATA PEMOHON ========== */
        .data-section {
            margin-left: 1cm;
            margin-bottom: 15pt;
        }

        .data-table td {
            padding: 3pt 0;
            vertical-align: top;
            font-size: 12pt;
        }

        .data-label {
            width: 150px;
            font-weight: bold;
        }

        .data-titik {
            width: 15px;
            text-align: center;
        }

        .data-isi {
            padding-left: 5px;
        }

        /* ========== KOTAK DETAIL PERMOHONAN ========== */
        .detail-section {
            margin-left: 1cm;
            margin-right: 0.5cm;
            margin-bottom: 15pt;
        }

        .detail-box {
            border: 1px solid #000;
            padding: 10pt 12pt;
        }

        .detail-judul {
            font-size: 11pt;
            font-weight: bold;
            margin-bottom: 6pt;
        }

        .detail-list {
            margin: 0;
            padding-left: 15pt;
        }

        .detail-list li {
            font-size: 11pt;
            margin-bottom: 3pt;
            line-height: 1.4;
        }

        .detail-kosong {
            font-size: 11pt;
            font-style: italic;
        }

        /* ========== PARAGRAF PENUTUP ========== */
        .penutup {
            text-align: justify;
            text-justify: inter-word;
            text-indent: 1cm;
            margin-bottom: 30pt;
            line-height: 1.5;
        }

        /* ========== TABEL TANDA TANGAN ========== */
        .ttd-table {
            width: 100%;
            page-break-inside: avoid;
        }

        .ttd-kolom {
            width: 50%;
            text-align: center;
            vertical-align: top;
        }

        .ttd-kolom p {
            margin: 0;
            text-align: center;
        }

        .ttd-ruang {
            height: 65pt; /* Ruang kosong untuk tanda tangan fisik/basah */
        }

        .ttd-garis {
            font-weight: bold;
            letter-spacing: 1px;
        }

        .ttd-nama {
            font-weight: bold;
            margin-top: 3px;
        }

        .ttd-nip {
            font-size: 10pt;
            margin-top: 2px;
        }
    </style>
</head>

<body>

    <!-- ========== KOP SURAT ========== -->
    <table class="kop-table">
        <tr>
            <!-- Logo Kiri: Pemerintah Kota Bontang -->
            <td class="logo-kiri">
                @php $logoPemerintah = public_path('images/logo-pemerintah.png'); @endphp
                @if(file_exists($logoPemerintah))
                    <img src="data:image/png;base64,{{ base64_encode(file_get_contents($logoPemerintah)) }}" class="kop-logo" alt="Logo Pemerintah">
                @endif
            </td>

            <!-- Teks Tengah Kop -->
            <td class="kop-isi">
                <div class="kop-instansi">Pemerintah Kota Bontang</div>
                <div class="kop-dinas">Dinas Komunikasi dan Informatika</div>
                <div class="kop-alamat">Jl. Brigjen Katamso No. 1, Bontang Utara, Kota Bontang, Kalimantan Timur</div>
                <div class="kop-alamat">Telp: (0548) 22222 | Website: kominfo.bontangkota.go.id</div>
            </td>

            <!-- Logo Kanan: Kominfo -->
            <td class="logo-kanan">
                @php $logoKominfo = public_path('images/logo-kominfo.png'); @endphp
                @if(file_exists($logoKominfo))
                    <img src="data:image/png;base64,{{ base64_encode(file_get_contents($logoKominfo)) }}" class="kop-logo" alt="Logo Kominfo">
                @endif
            </td>
        </tr>
    </table>

    <!-- ========== JUDUL SURAT ========== -->
    <div class="judul-section">
        <div class="judul-text">BUKTI PENERIMAAN LAYANAN</div>
        <div class="nomor-text">Nomor: {{ $ticket->id }}/KOMINFO/{{ date('m/Y', strtotime($ticket->created_at)) }}</div>
    </div>

    <!-- ========== PEMBUKA ========== -->
    <div class="pembuka">
        Yang bertanda tangan di bawah ini, Kepala Dinas Komunikasi dan Informatika Kota Bontang, dengan ini menerangkan bahwa telah menerima permohonan layanan dari:
    </div>

    <!-- ========== DATA PEMOHON ========== -->
    <div class="data-section">
        <table class="data-table">
            <tr>
                <td class="data-label">Nama Pemohon</td>
                <td class="data-titik">:</td>
                <td class="data-isi">{{ $ticket->requester->name ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td class="data-label">Email / OPD</td>
                <td class="data-titik">:</td>
                <td class="data-isi">{{ $ticket->requester->email ?? '-' }}</td>
            </tr>
            <tr>
                <td class="data-label">Jenis Layanan</td>
                <td class="data-titik">:</td>
                <td class="data-isi">{{ $ticket->service->name ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td class="data-label">Hari / Tanggal</td>
                <td class="data-titik">:</td>
                <td class="data-isi">{{ \Carbon\Carbon::parse($ticket->created_at)->locale('id')->isoFormat('dddd, D MMMM Y') }}</td>
            </tr>
            @if($ticket->schedule_start)
            <tr>
                <td class="data-label">Jadwal Layanan</td>
                <td class="data-titik">:</td>
                <td class="data-isi">
                    {{ \Carbon\Carbon::parse($ticket->schedule_start)->locale('id')->isoFormat('D MMMM Y, H:i') }} s.d. {{ \Carbon\Carbon::parse($ticket->schedule_end)->format('H:i') }} WITA
                </td>
            </tr>
            @endif
            <tr>
                <td class="data-label">Pelaksana Staf</td>
                <td class="data-titik">:</td>
                <td class="data-isi">{{ $ticket->staff->name ?? 'Belum Ditugaskan' }}</td>
            </tr>
            <tr>
                <td class="data-label">Status</td>
                <td class="data-titik">:</td>
                <td class="data-isi" style="font-weight: bold;">{{ strtoupper($ticket->status) }}</td>
            </tr>
        </table>
    </div>

    <!-- ========== DETAIL PERMOHONAN ========== -->
    <div class="detail-section">
        <div class="detail-box">
            <div class="detail-judul">Detail permohonan yang diajukan:</div>
            @if($ticket->form_data && count($ticket->form_data) > 0)
                <ul class="detail-list">
                    @foreach($ticket->form_data as $key => $value)
                        @if($key !== 'wa')
                        <li>
                            <strong>{{ ucfirst(str_replace('_', ' ', $key)) }}:</strong>
                            @if(is_array($value))
                                {{ implode(', ', $value) }}
                            @else
                                {{ $value }}
                            @endif
                        </li>
                        @endif
                    @endforeach
                </ul>
            @else
                <div class="detail-kosong">Tidak ada detail tambahan.</div>
            @endif
        </div>
    </div>

    <!-- ========== PENUTUP ========== -->
    <div class="penutup">
        Surat bukti ini dibuat secara otomatis oleh sistem SIKOMA Dinas Komunikasi dan Informatika Kota Bontang untuk dapat dipergunakan sebagaimana mestinya.
    </div>

    <!-- ========== TANDA TANGAN ========== -->
    <table class="ttd-table">
        <tr>
            <td class="ttd-kolom">
                <p>Bontang, {{ \Carbon\Carbon::now()->locale('id')->isoFormat('D MMMM Y') }}</p>
                <p>Pelaksana Layanan,</p>
                <div class="ttd-ruang"></div>
                <p class="ttd-garis">________________________</p>
                <p class="ttd-nama">{{ $ticket->staff->name ?? 'Belum Ditugaskan' }}</p>
                <p class="ttd-nip">NIP. {{ $ticket->staff->nip ?? '................................' }}</p>
            </td>
            <td class="ttd-kolom">
                <p>Bontang, {{ \Carbon\Carbon::now()->locale('id')->isoFormat('D MMMM Y') }}</p>
                <p>Kepala Dinas Kominfo,</p>
                <div class="ttd-ruang"></div>
                <p class="ttd-garis">________________________</p>
                <p class="ttd-nama">________________________</p>
                <p class="ttd-nip">NIP. ................................</p>
            </td>
        </tr>
    </table>

</body>
</html>