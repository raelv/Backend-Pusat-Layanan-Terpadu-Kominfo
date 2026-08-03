<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Permohonan Layanan</title>
    <style>
        @page { margin: 25mm 25mm 30mm 30mm; size: A4; }
        
        body { 
            font-family: 'Times New Roman', Times, serif; 
            font-size: 12pt; 
            line-height: 1.6; 
            color: #000; 
        }

        /* KOP SURAT */
        .header {
            text-align: center;
            border-bottom: 3px solid #000;
            padding-bottom: 10px;
            margin-bottom: 30px;
        }
        .header img { width: 70px; height: auto; margin-bottom: 5px; }
        .header h1 { margin: 0; font-size: 14pt; letter-spacing: 2px; }
        .header h2 { margin: 3px 0; font-size: 13pt; }
        .header p { margin: 2px 0; font-size: 10pt; }

        /* JUDUL SURAT */
        .title {
            text-align: center;
            margin-bottom: 25px;
        }
        .title h3 {
            margin: 0;
            text-decoration: underline;
            font-size: 13pt;
        }
        .title p { margin: 5px 0 0 0; font-size: 11pt; }

        /* ISI SURAT */
        .content {
            text-align: justify;
            margin-bottom: 20px;
        }
        .content p {
            margin: 0 0 15px 0;
            text-indent: 40px; /* Paragraf menjorok ke dalam */
        }

        /* TABEL DATA */
        table.data {
            margin: 0 0 20px 40px;
            font-size: 12pt;
        }
        table.data td {
            padding: 2px 0;
            vertical-align: top;
        }
        table.data td.label {
            width: 160px;
        }

        /* TANDA TANGAN */
        .signature {
            float: right;
            width: 250px;
            text-align: center;
            margin-top: 50px;
        }
        .signature .space { height: 80px; }
        .signature .name { font-weight: bold; text-decoration: underline; }
        .signature .nip { font-size: 10pt; }

        .clear { clear: both; }
    </style>
</head>
<body>

    <!-- KOP SURAT -->
    <div class="header">
        <img src="{{ asset('images/logo-kominfo.png') }}" alt="Logo Kominfo">
        <h1>PEMERINTAH KOTA BONTANG</h1>
        <h2>DINAS KOMUNIKASI DAN INFORMATIKA</h2>
        <p>Jl. Brigjen Katamso No. 1, Bontang Utara, Kota Bontang, Kalimantan Timur</p>
        <p>Telp: (0548) 22222 | Website: kominfo.bontangkota.go.id</p>
    </div>

    <!-- JUDUL SURAT -->
    <div class="title">
        <h3>SURAT BUKTI PENERIMAAN LAYANAN</h3>
        <p>Nomor: {{ $ticket->id }}/KOMINFO/{{ date('m/Y', strtotime($ticket->created_at)) }}</p>
    </div>

    <!-- ISI SURAT -->
    <div class="content">
        <p>Yang bertanda tangan di bawah ini, Kepala Dinas Komunikasi dan Informatika Kota Bontang, dengan ini menerangkan bahwa telah menerima permohonan layanan dari:</p>
        
        <table class="data">
            <tr>
                <td class="label">Nama Pemohon</td>
                <td>: {{ $ticket->requester->name ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td class="label">Email / OPD</td>
                <td>: {{ $ticket->requester->email ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Jenis Layanan</td>
                <td>: {{ $ticket->service->name ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td class="label">Hari / Tanggal</td>
                <td>: {{ \Carbon\Carbon::parse($ticket->created_at)->translatedFormat('l, d F Y') }}</td>
            </tr>
            @if($ticket->schedule_start)
            <tr>
                <td class="label">Jadwal Layanan</td>
                <td>: {{ \Carbon\Carbon::parse($ticket->schedule_start)->format('d F Y H:i') }} s.d {{ \Carbon\Carbon::parse($ticket->schedule_end)->format('d F Y H:i') }}</td>
            </tr>
            @endif
            <tr>
                <td class="label">Pelaksana Staf</td>
                <td>: {{ $ticket->staff->name ?? 'Belum Ditugaskan' }}</td>
            </tr>
        </table>

        <p>Adapun keterangan/detail permohonan yang diajukan adalah sebagai berikut:</p>
        
        <div style="margin: 0 0 20px 40px; border: 1px solid #000; padding: 10px;">
            <ul style="margin: 0; padding-left: 20px;">
                @if($ticket->form_data)
                    @foreach($ticket->form_data as $key => $value)
                        <li>{{ ucfirst(str_replace('_', ' ', $key)) }}: {{ is_array($value) ? implode(', ', $value) : $value }}</li>
                    @endforeach
                @else
                    <li>Tidak ada detail tambahan.</li>
                @endif
            </ul>
        </div>

        <p>Surat bukti ini dibuat secara otomatis oleh sistem untuk dapat dipergunakan sebagaimana mestinya.</p>
    </div>

    <!-- TANDA TANGAN -->
    <div class="signature">
        <p>Bontang, {{ \Carbon\Carbon::now()->translatedFormat('d F Y') }}</p>
        <p>Kepala Dinas Kominfo,</p>
        <div class="space"></div>
        <p class="name">________________________</p>
        <p class="nip">NIP. ................................</p>
    </div>

    <div class="clear"></div>

</body>
</html>