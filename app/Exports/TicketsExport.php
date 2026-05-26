<?php

namespace App\Exports;

use App\Models\Ticket;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class TicketsExport implements FromCollection, WithHeadings, WithMapping
{
    public function collection()
    {
        return Ticket::with(['requester', 'service', 'staff'])->get();
    }

    public function headings(): array
    {
        return [
            'ID Tiket',
            'Pemohon (OPD)',
            'Email Pemohon',
            'Layanan',
            'Status',
            'Staf Pelaksana',
            'Jadwal Mulai',
            'Jadwal Selesai',
            'Tanggal Dibuat'
        ];
    }

    public function map($ticket): array
    {
        return [
            $ticket->id,
            $ticket->requester->name ?? 'N/A',
            $ticket->requester->email ?? 'N/A',
            $ticket->service->name ?? 'N/A',
            strtoupper($ticket->status),
            $ticket->staff->name ?? 'Belum Ditugaskan',
            $ticket->schedule_start ? \Carbon\Carbon::parse($ticket->schedule_start)->format('d F Y H:i') : '-',
            $ticket->schedule_end ? \Carbon\Carbon::parse($ticket->schedule_end)->format('d F Y H:i') : '-',
            \Carbon\Carbon::parse($ticket->created_at)->format('d F Y'),
        ];
    }
}