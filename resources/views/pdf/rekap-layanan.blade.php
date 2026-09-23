<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">

    <title>Laporan Rekapitulasi Layanan</title>

    <style>

        @page {
            size: A4 landscape;
            margin: 1.5cm 1.5cm 1.8cm 1.5cm;
        }

        body {
            margin: 0;
            padding: 0;

            font-family: "Times New Roman", Times, serif;
            font-size: 9pt;

            color: #000000;

            line-height: 1.3;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        td,
        th {
            vertical-align: middle;
        }

        p {
            margin: 0;
            padding: 0;
        }

        .center {
            text-align: center;
        }

        .left {
            text-align: left;
        }

        .right {
            text-align: right;
        }

        .bold {
            font-weight: bold;
        }

        .underline {
            text-decoration: underline;
        }

        .small {
            font-size: 7.5pt;
        }

        .info-text {
            font-size: 8pt;
        }

        .kop-table {
            width: 100%;
            border-collapse: collapse;
        }

        .kop-table td {
            border: none;
            padding: 0;
        }

        .logo-cell {
            width: 65px;
            text-align: center;
            vertical-align: middle;
        }

        .logo {
            width: 52px;
            height: 52px;
        }

        .kop-content {
            text-align: center;
        }

        .kop-judul {
            font-size: 12.5pt;
            font-weight: bold;
            line-height: 1.2;
        }

        .kop-sub {
            font-size: 10.5pt;
            font-weight: bold;
            line-height: 1.25;
        }

        .kop-alamat {
            font-size: 7.5pt;
            line-height: 1.25;
        }

        .kop-garis {
            height: 7px;
            border-bottom: 3px double #000000;
        }

        .judul-wrapper {
            margin-top: 14pt;
            margin-bottom: 12pt;
        }

        .judul-laporan {
            font-size: 13pt;
            font-weight: bold;
            text-decoration: underline;
        }

        .periode {
            margin-top: 4pt;
            font-size: 8pt;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            padding: 5px 4px;

            background-color: #1F4E79;
            color: #FFFFFF;

            border: 1px solid #000000;

            font-size: 7.8pt;
            font-weight: bold;

            text-align: center;
            vertical-align: middle;

            line-height: 1.2;
        }

        .data-table td {
            padding: 4px 4px;

            border: 1px solid #000000;

            font-size: 7.8pt;

            vertical-align: middle;

            line-height: 1.3;
        }

        .col-no {
            width: 3%;
        }

        .col-ticket {
            width: 7%;
        }

        .col-category {
            width: 10%;
        }

        .col-requester {
            width: 19%;
        }

        .col-title {
            width: 23%;
        }

        .col-created {
            width: 9%;
        }

        .col-schedule {
            width: 15%;
        }

        .col-staff {
            width: 14%;
        }

        .ganjil {
            background-color: #F4F6F8;
        }

        .genap {
            background-color: #FFFFFF;
        }

        .text-center {
            text-align: center;
        }

        .text-left {
            text-align: left;
        }

        .empty-row td {
            padding: 18px 10px;

            text-align: center;

            font-size: 8pt;
            font-style: italic;
        }

        .footer-wrapper {
            margin-top: 8pt;
        }

        .footer-table {
            width: 100%;
            border-collapse: collapse;
        }

        .footer-table td {
            border: none;
            padding: 0;

            font-size: 7.5pt;
        }

        .signature-wrapper {
            margin-top: 18pt;
        }

        .signature-table {
            width: 100%;
            border-collapse: collapse;
        }

        .signature-table td {
            border: none;
            padding: 0;
        }

        .signature-space-left {
            width: 68%;
        }

        .signature {
            width: 32%;

            text-align: center;

            font-size: 8pt;
        }

        .signature p {
            margin: 0;
            padding: 0;
        }

        .signature-space {
            height: 55pt;
        }

        .signature-name {
            font-weight: bold;
            text-decoration: underline;
        }

        .nip {
            margin-top: 2pt !important;
            font-size: 7.5pt;
        }

        img {
            display: inline-block;
        }

        ul {
            margin-top: 0;
        }

    </style>

</head>


<body>

    <table class="kop-table">

        <tr>

            <td class="logo-cell" rowspan="4">

                @if(file_exists(public_path('images/logo-kominfo.png')))

                    <img
                        src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/logo-kominfo.png'))) }}"
                        class="logo"
                        alt="Logo Pemerintah Kota Bontang"
                    >

                @endif

            </td>

            <td class="kop-content">

                <div class="kop-judul">
                    PEMERINTAH KOTA BONTANG
                </div>

            </td>

        </tr>

        <tr>

            <td class="kop-content">

                <div class="kop-sub">
                    DINAS KOMUNIKASI DAN INFORMATIKA
                </div>

            </td>

        </tr>

        <tr>

            <td class="kop-content">

                <div class="kop-alamat">
                    Jl. Brigjen Katamso No. 1, Bontang Utara,
                    Kota Bontang
                </div>

            </td>

        </tr>

        <tr>

            <td class="kop-content">

                <div class="kop-alamat">
                    Telp: (0548) 22222
                    &nbsp;|&nbsp;
                    Website: kominfo.bontangkota.go.id
                </div>

            </td>

        </tr>

        <tr>

            <td></td>

            <td class="kop-garis"></td>

        </tr>

    </table>

    <table class="judul-wrapper">

        <tr>

            <td class="center">

                <div class="judul-laporan">
                    LAPORAN REKAPITULASI LAYANAN
                </div>

                <div class="periode">

                    Periode:
                    {{ $start_date ? \Carbon\Carbon::parse($start_date)->locale('id')->translatedFormat('d F Y') : 'Awal Data' }}

                    s/d

                    {{ $end_date ? \Carbon\Carbon::parse($end_date)->locale('id')->translatedFormat('d F Y') : 'Data Terbaru' }}

                    &nbsp;&nbsp;|&nbsp;&nbsp;

                    Layanan:
                    {{ $filter_service ?? 'Semua Layanan' }}

                    &nbsp;&nbsp;|&nbsp;&nbsp;

                    Status:
                    {{ $filter_status ?? 'Semua Status' }}

                </div>

            </td>

        </tr>

    </table>

    <table class="data-table">

        <thead>

            <tr>

                <th class="col-no">
                    No
                </th>

                <th class="col-ticket">
                    ID Tiket
                </th>

                <th class="col-category">
                    Kategori
                </th>

                <th class="col-requester">
                    Instansi / Pemohon
                </th>

                <th class="col-title">
                    Judul / Perihal
                </th>

                <th class="col-created">
                    Tgl Pengajuan
                </th>

                <th class="col-schedule">
                    Tgl Pelaksanaan
                </th>

                <th class="col-staff">
                    Staff / Teknisi
                </th>

            </tr>

        </thead>

        <tbody>

            @forelse($tickets as $index => $ticket)

                @php

                    $judul = $ticket->report_title;

                    $pelaksanaan = '-';

                    if ($ticket->schedule_start) {

                        $pelaksanaan =
                            $ticket->schedule_start->format('d/m/Y H:i')
                            . ' s/d '
                            . ($ticket->schedule_end ? $ticket->schedule_end->format('H:i') : '-')
                            . ' WITA';

                    } elseif ($ticket->due_date) {

                        $pelaksanaan =
                            $ticket->due_date->format('d/m/Y');

                    }

                    $pemohon =
                        ($ticket->requester->name ?? '-')
                        . ' ('
                        . ($ticket->requester->bidang ?? 'OPD')
                        . ')';

                    $staffName = $ticket->report_staff_name;

                    $statusLabel = strtoupper($ticket->status);

                    if (
                        in_array(
                            $ticket->status,
                            [
                                'assigned',
                                'in_progress',
                                'approved_admin'
                            ]
                        )
                    ) {

                        $statusLabel = 'DIPROSES';

                    }

                    if (
                        in_array(
                            $ticket->status,
                            [
                                'pending',
                                'queued'
                            ]
                        )
                    ) {

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

                    $rowClass =
                        ($index % 2 === 0)
                        ? 'ganjil'
                        : 'genap';

                @endphp

                <tr class="{{ $rowClass }}">

                    <td class="text-center">
                        {{ $index + 1 }}
                    </td>

                    <td class="text-center">
                        #{{ $ticket->ticket_number }}
                    </td>

                    <td class="text-center">
                        {{ strtoupper($ticket->service->category ?? '-') }}
                    </td>

                    <td class="text-left">
                        {{ $pemohon }}
                    </td>

                    <td class="text-left">
                        {{ $judul }}
                    </td>

                    <td class="text-center">
                        {{ $ticket->created_at->format('d/m/Y') }}
                    </td>

                    <td class="text-center">
                        {{ $pelaksanaan }}
                    </td>

                    <td class="text-left">
                        {{ $staffName }}
                    </td>

                </tr>

            @empty

                <tr class="empty-row">

                    <td colspan="8">

                        Tidak ada data pada periode yang dipilih.

                    </td>

                </tr>

            @endforelse

        </tbody>

    </table>

    <div class="footer-wrapper">

        <table class="footer-table">

            <tr>

                <td>

                    <strong>Total Data:</strong>
                    {{ $tickets->count() }} tiket

                </td>

                <td class="right">

                    Dicetak:
                    {{ $printed_at ?? now()->locale('id')->translatedFormat('d F Y, H:i') }}
                    WITA

                </td>

            </tr>

        </table>

    </div>

    <div class="signature-wrapper">

        <table class="signature-table">

            <tr>

                <td class="signature-space-left">
                    &nbsp;
                </td>

                <td class="signature">

                    <p>

                        Bontang,
                        {{ \Carbon\Carbon::now()->locale('id')->translatedFormat('d F Y') }}

                    </p>

                    <p>
                        Kepala Dinas Kominfo,
                    </p>

                    <div class="signature-space">
                        &nbsp;
                    </div>

                    <p class="signature-name">

                        ____________________________

                    </p>

                    <p class="nip">

                        NIP. ........................................

                    </p>

                </td>

            </tr>

        </table>

    </div>

</body>

</html>