<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\Service;
use App\Models\User;
use App\Models\TicketLog;
use App\Models\ZoomLink;
use App\Jobs\SendTelegramJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpWord\PhpWord;
use App\Exports\BuktiLayananExport;
use Illuminate\Support\Facades\Storage;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = Ticket::with(['service', 'staff', 'requester', 'zoomLink', 'comments.user']);

        if ($user->role === 'admin') {
        } elseif ($user->role === 'staff') {
            $query->where(function ($q) use ($user) {
                $q->where('assigned_staff_id', $user->id)
                ->orWhere(function ($q2) {
                    $q2->whereNull('assigned_staff_id')
                        ->whereIn('status', ['pending', 'queued']);
                });
            });
        } else {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('status')) {
            if ($request->status === 'in_progress') {
                $query->whereIn('status', ['assigned', 'in_progress']);
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->has('service_type')) {
            $type = $request->service_type;
            $query->whereHas('service', function ($q) use ($type) {
                if ($type === 'it') {
                    $q->where('category', 'it');
                } elseif ($type === 'zoom') {
                    $q->where('category', 'zoom');
                } elseif ($type === 'command_center') {
                    $q->where('category', 'command_center');
                }
            });
        }

        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = $request->search;

            if (is_numeric($searchTerm)) {
                $query->where('ticket_number', (int)$searchTerm);
            } else {
                $query->where(function($q) use ($searchTerm) {
                    $q->whereHas('requester', function($q2) use ($searchTerm) {
                        $q2->where('name', 'ILIKE', "%{$searchTerm}%");
                    })
                    ->orWhereHas('service', function($q2) use ($searchTerm) {
                        $q2->where('name', 'ILIKE', "%{$searchTerm}%");
                    })
                    ->orWhere('form_data', 'ILIKE', "%{$searchTerm}%");
                });
            }
        }

        return $query->orderBy('created_at', 'desc')->paginate(15);
    }

    public function getActiveSchedules()
    {
        $schedules = Ticket::with(['service', 'staff'])
            ->whereHas('service', function ($q) {
                $q->whereIn('category', ['zoom', 'command_center']);
            })
            ->whereIn('status', ['assigned', 'in_progress', 'approved_admin'])
            ->whereNotNull('schedule_start')
            ->whereNotNull('schedule_end')
            ->orderBy('schedule_start', 'asc')
            ->get();

        return response()->json($schedules);
    }

    public function show($id)
    {
        $user = Auth::user();

        // ✅ FIX UTAMA: Ditambahkan 'zoomLink' sesuai permintaan FE
        $ticket = Ticket::with([
            'service', 
            'staff', 
            'requester', 
            'zoomLink', 
            'comments' => function($query) use ($id) {
                $query->where('ticket_id', $id);
            },
            'comments.user'
        ])->find($id);

        if (!$ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        if ($user->role === 'opd' && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki izin untuk melihat tiket ini.'], 403);
        }

        if ($user->role === 'staff'
            && $ticket->assigned_staff_id !== $user->id
            && !($ticket->assigned_staff_id === null && in_array($ticket->status, ['pending', 'queued']))) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki izin untuk melihat tiket ini.'], 403);
        }

        return response()->json($ticket);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        $service = Service::find($request->service_id);
        if (!$service) {
            return response()->json(['message' => 'Layanan tidak ditemukan'], 404);
        }

        $isScheduleBased = $service->is_schedule_based;
        $category = strtolower($service->category);

        if (!in_array(strtolower($user->role), ['admin', 'staff', 'pimpinan'])) {
            $now = \Carbon\Carbon::now('Asia/Makassar');

            if ($now->isWeekend()) {
                return response()->json([
                    'message' => 'Layanan hanya dapat diajukan pada hari Senin sampai Jumat.',
                    'detail' => 'Hari ini adalah hari ' . $now->locale('id')->format('l') . '. Pengajuan layanan hanya bisa dilakukan pada hari kerja.',
                ], 422);
            }

            if ($category === 'command_center') {
                $startTime = \Carbon\Carbon::createFromTime(7, 30, 0, 'Asia/Makassar');
                $endTime = \Carbon\Carbon::createFromTime(16, 0, 0, 'Asia/Makassar');

                if ($now->lt($startTime) || $now->gt($endTime)) {
                    return response()->json([
                        'message' => 'Layanan Command Center hanya dapat diajukan pada jam 07.30 - 16.00 WITA.',
                        'detail' => 'Saat ini pukul ' . $now->format('H.i') . ' WITA.',
                    ], 422);
                }
            }
        }

        $validationRules = [
            'service_id' => 'required|exists:services,id',
            'form_data' => 'required',
            'surat_permohonan' => 'required|file|mimes:pdf|max:5120',
            'lampiran_tambahan' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
            'resubmit_of' => 'nullable|integer|exists:tickets,id',
        ];

        if ($isScheduleBased) {
            $validationRules['schedule_start'] = 'required|date';
            $validationRules['schedule_end'] = 'required|date|after:schedule_start';
        } else {
            $validationRules['schedule_start'] = 'nullable|date';
        }

        $request->validate($validationRules, [
            'service_id.required' => 'Layanan wajib dipilih.',
            'service_id.exists' => 'Layanan yang dipilih tidak valid.',
            'form_data.required' => 'Formulir pengajuan wajib diisi.',
            'surat_permohonan.required' => 'Surat permohonan wajib diunggah (PDF, maks 5MB).',
            'surat_permohonan.file' => 'Surat permohonan harus berupa berkas.',
            'surat_permohonan.mimes' => 'Surat permohonan harus berformat PDF.',
            'surat_permohonan.max' => 'Ukuran surat permohonan maksimal 5MB.',
            'lampiran_tambahan.file' => 'Lampiran tambahan harus berupa berkas.',
            'lampiran_tambahan.mimes' => 'Lampiran tambahan harus berformat PDF, JPG, PNG, atau WEBP.',
            'lampiran_tambahan.max' => 'Ukuran lampiran tambahan maksimal 10MB.',
            'schedule_start.required' => 'Tanggal dan jam mulai kegiatan wajib diisi.',
            'schedule_start.date' => 'Format tanggal mulai tidak valid.',
            'schedule_end.required' => 'Tanggal dan jam selesai kegiatan wajib diisi.',
            'schedule_end.date' => 'Format tanggal selesai tidak valid.',
            'schedule_end.after' => 'Jam selesai kegiatan harus setelah jam mulai.',
            'resubmit_of.integer' => 'Data pengajuan ulang tidak valid.',
            'resubmit_of.exists' => 'Tiket sumber pengajuan ulang tidak ditemukan.',
        ]);

        $formData = $request->form_data;

        if (is_string($formData)) {
            $formData = json_decode($formData, true);
        }

        if (!is_array($formData)) {
            $formData = [];
        }

        $mapKeys = [
            'jumlahPeserta' => 'jumlah_peserta',
            'namaAcara' => 'nama_acara',
            'namaAplikasi' => 'nama_aplikasi',
            'waktuMulai' => 'waktu_mulai',
            'waktuSelesai' => 'waktu_selesai',
            'topik' => 'topik',
            'estimasi' => 'estimasi',
        ];

        foreach ($mapKeys as $camelKey => $snakeKey) {
            if (isset($formData[$camelKey]) && !isset($formData[$snakeKey])) {
                $formData[$snakeKey] = $formData[$camelKey];
                unset($formData[$camelKey]);
            }
        }

        if (isset($formData['nama'])) {
            $nama = trim($formData['nama']);

            if (!preg_match('/^[a-zA-Z\s.\-,\']+$/', $nama)) {
                return response()->json([
                    'message' => 'Kolom Nama Lengkap tidak valid. Nama hanya boleh berisi huruf, spasi, dan tanda baca gelar (titik, koma, tanda hubung). Angka dan simbol tidak diperbolehkan.',
                    'error_field' => 'nama'
                ], 422);
            }

            if (empty($nama)) {
                return response()->json([
                    'message' => 'Nama Lengkap wajib diisi.',
                    'error_field' => 'nama'
                ], 422);
            }

            $formData['nama'] = $nama;
        }

        if ($category === 'command_center') {
            $jumlahPeserta = isset($formData['jumlah_peserta']) ? (int)$formData['jumlah_peserta'] : null;

            if (is_null($jumlahPeserta)) {
                return response()->json([
                    'message' => 'Kolom Jumlah Peserta wajib diisi untuk layanan Command Center.',
                    'error_field' => 'jumlah_peserta'
                ], 422);
            }

            if ($jumlahPeserta < 3) {
                return response()->json([
                    'message' => 'Jumlah peserta tidak boleh kurang dari 3 orang (kapasitas minimal Command Center).',
                    'detail' => 'Anda mengajukan ' . $jumlahPeserta . ' peserta. Minimal peserta adalah 3 orang.',
                    'error_field' => 'jumlah_peserta',
                    'min' => 3
                ], 422);
            }

            if ($jumlahPeserta > 50) {
                return response()->json([
                    'message' => 'Jumlah peserta tidak boleh melebihi 50 orang (kapasitas maksimal Command Center).',
                    'detail' => 'Anda mengajukan ' . $jumlahPeserta . ' peserta. Maksimal kapasitas Command Center adalah 50 orang.',
                    'error_field' => 'jumlah_peserta',
                    'max' => 50
                ], 422);
            }
        }

        if (isset($formData['wa'])) {
            $wa = preg_replace('/\s+/', '', $formData['wa']);

            if ($wa === '') {
                return response()->json([
                    'message' => 'Kolom Nomor WhatsApp wajib diisi.',
                    'error_field' => 'wa'
                ], 422);
            }

            try {
                $phone = new \Propaganistas\LaravelPhone\PhoneNumber($wa, 'ID');

                if (!$phone->isValid()) {
                    return response()->json([
                        'message' => 'Kolom Nomor WhatsApp tidak valid. Gunakan nomor Indonesia yang benar, contoh: 08123456789.',
                        'error_field' => 'wa'
                    ], 422);
                }

                $formData['wa'] = $phone->formatE164();

            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Format nomor WhatsApp tidak dikenali.',
                    'error_field' => 'wa'
                ], 422);
            }
        }

        $formError = $this->validateFormDataByCategory($category, $formData);
        if ($formError) {
            return response()->json($formError, 422);
        }

        if ($isScheduleBased) {
            $newStart = \Carbon\Carbon::parse($request->schedule_start, 'Asia/Makassar');
            $newEnd = \Carbon\Carbon::parse($request->schedule_end, 'Asia/Makassar');
            $nowWita = \Carbon\Carbon::now('Asia/Makassar');

            $durasiJam = $newStart->diffInMinutes($newEnd) / 60;
            $layananLabel = $category === 'zoom' ? 'Zoom' : 'Command Center';

            if ($newStart->lt($nowWita)) {
                return response()->json([
                    'message' => 'Tidak dapat melakukan pemesanan untuk jadwal yang sudah lewat.',
                    'detail' => 'Waktu yang Anda pilih (' . $newStart->format('d F Y, H.i') . ' WITA) sudah berlalu. Silakan pilih jadwal yang akan datang.',
                    'error_field' => 'schedule_start'
                ], 422);
            }

            if ($durasiJam > 6) {
                return response()->json([
                    'message' => 'Durasi pemesanan ' . $layananLabel . ' melebihi batas maksimal.',
                    'detail' => 'Berdasarkan SOP, durasi maksimal pemesanan ' . $layananLabel . ' adalah 6 jam. Anda mengajukan durasi ' . round($durasiJam, 1) . ' jam.',
                    'error_field' => 'schedule_end',
                    'max_duration' => 6,
                    'current_duration' => round($durasiJam, 1)
                ], 422);
            }

            if ($category === 'command_center') {
                if ($newStart->isWeekend()) {
                    return response()->json([
                        'message' => 'Gagal mengajukan. Jadwal Command Center hanya tersedia hari Senin - Jumat.',
                        'detail' => 'Hari yang Anda pilih adalah hari weekend. Command Center tidak beroperasi di hari weekend.',
                        'error_field' => 'schedule_start',
                        'allowed_days' => ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat']
                    ], 422);
                }

                if ($newStart->format('H:i') < '07:30') {
                    return response()->json([
                        'message' => 'Jam mulai pemesanan Command Center di luar jam operasional.',
                        'detail' => 'Jam operasional Command Center dimulai pukul 07.30 WITA. Anda memilih jam ' . $newStart->format('H.i') . ' WITA.',
                        'error_field' => 'schedule_start',
                        'min_time' => '07:30'
                    ], 422);
                }

                if ($newEnd->format('H:i') > '16:00') {
                    return response()->json([
                        'message' => 'Jam selesai pemesanan Command Center di luar jam operasional.',
                        'detail' => 'Jam operasional Command Center berakhir pukul 16.00 WITA. Anda memilih jam ' . $newEnd->format('H.i') . ' WITA.',
                        'error_field' => 'schedule_end',
                        'max_time' => '16:00'
                    ], 422);
                }

                $isConflict = Ticket::whereHas('service', function ($q) {
                    $q->where('category', 'command_center');
                })
                ->whereIn('status', ['pending', 'assigned', 'in_progress', 'approved_admin'])
                ->whereNotNull('schedule_start')
                ->whereNotNull('schedule_end')
                ->where(function ($query) use ($newStart, $newEnd) {
                    $query->where('schedule_start', '<', $newEnd)
                        ->where('schedule_end', '>', $newStart);
                })->exists();

                if ($isConflict) {
                    return response()->json([
                        'message' => 'Jadwal yang dipilih bentrok dengan booking Command Center lain pada tanggal dan jam yang sama. Silakan pilih jadwal lain.',
                        'error_field' => 'schedule_start'
                    ], 422);
                }
            }

                if ($category === 'zoom') {
                    $totalLinks = \App\Models\ZoomLink::count();

                    if ($totalLinks === 0) {
                        return response()->json([
                            'message' => 'Tidak ada link Zoom tersedia pada sistem. Silakan hubungi Admin.',
                            'detail' => 'Belum ada link Zoom yang didaftarkan ke sistem SIKOMA.',
                            'error_field' => 'schedule_start'
                        ], 422);
                    }

                    $bookingDiJadwal = Ticket::whereHas('service', function ($q) {
                        $q->where('category', 'zoom');
                    })
                    ->whereIn('status', ['assigned', 'in_progress', 'approved_admin'])
                    ->whereNotNull('schedule_start')
                    ->whereNotNull('schedule_end')
                    ->where(function ($query) use ($newStart, $newEnd) {
                        $query->where('schedule_start', '<', $newEnd)
                            ->where('schedule_end', '>', $newStart);
                    })
                    ->count();

                    if ($bookingDiJadwal >= $totalLinks) {
                        return response()->json([
                            'message' => 'Jadwal bentrok dengan booking Zoom lain.',
                            'detail' => 'Seluruh link Zoom (' . $totalLinks . ') sudah dialokasikan untuk jadwal yang sudah disetujui pada jam tersebut (' . $bookingDiJadwal . ' booking). Silakan pilih jadwal berbeda.',
                            'error_field' => 'schedule_start',
                            'total_links' => $totalLinks,
                            'bookings_in_slot' => $bookingDiJadwal
                        ], 422);
                    }
                }
        }

        if ($request->filled('resubmit_of')) {
            $source = Ticket::find($request->resubmit_of);

            if (!$source || $source->user_id !== $user->id || $source->status !== 'expired') {
                return response()->json(['message' => 'Tiket sumber tidak valid untuk pengajuan ulang.'], 422);
            }

            if ($source->resubmitted_at !== null) {
                return response()->json(['message' => 'Tiket ini sudah pernah diajukan ulang dan terkunci.'], 422);
            }

            $source->update(['resubmitted_at' => now()]);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $user, $service, $isScheduleBased, $category, $formData) {

            $suratPath = null;
            $lampiranPath = null;

            $fail = function ($message, $status = 422) use (&$suratPath, &$lampiranPath) {
                if ($suratPath) \Illuminate\Support\Facades\Storage::disk('local')->delete($suratPath);
                if ($lampiranPath) \Illuminate\Support\Facades\Storage::disk('local')->delete($lampiranPath);
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json(['message' => $message], $status)
                );
            };

            try {
                if ($request->hasFile('surat_permohonan')) {
                    $file = $request->file('surat_permohonan');

                    $allowedMimes = ['application/pdf'];
                    $realMime = $file->getMimeType();

                    if (!in_array($realMime, $allowedMimes)) {
                        $fail('File surat permohonan mengandung format yang tidak diizinkan atau file rusak.');
                    }

                    $suratPath = $file->store('surat_permohonan', 'local');
                }

                if ($request->hasFile('lampiran_tambahan')) {
                    $file = $request->file('lampiran_tambahan');

                    $allowedMimesExtra = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
                    $realMime = $file->getMimeType();

                    if (!in_array($realMime, $allowedMimesExtra)) {
                        $fail('File lampiran mengandung format yang tidak diizinkan atau file rusak.');
                    }

                    $lampiranPath = $file->store('lampiran_tambahan', 'local');
                }
            } catch (\Exception $e) {
                $fail('Gagal mengupload file surat permohonan.', 500);
            }

            $dueDate = null;
            if ($isScheduleBased) {
                $newEnd = \Carbon\Carbon::parse($request->schedule_end, 'Asia/Makassar');
                $dueDate = $newEnd;
            } else {
                $dueDateInput = $request->input('due_date');

                if ($dueDateInput) {
                    $parsedDueDate = \Carbon\Carbon::parse($dueDateInput, 'Asia/Makassar')->startOfDay();
                    $minDate = \Carbon\Carbon::now('Asia/Makassar')->startOfDay()->addDays(89);

                    if ($parsedDueDate->lte($minDate)) {
                        $fail('Pengajuan ditolak. Berdasarkan SOP, pembuatan aplikasi website membutuhkan waktu minimal 3 Bulan (90 Hari).');
                    }
                    $dueDate = $parsedDueDate;
                } else {
                    $dueDate = \Carbon\Carbon::now('Asia/Makassar')->addMonths(3);
                }
            }

            $ticket = Ticket::create([
                'service_id' => $request->service_id,
                'user_id' => $user->id,
                'form_data' => $formData,
                'surat_permohonan_path' => $suratPath,
                'lampiran_tambahan_path' => $lampiranPath,
                'status' => 'pending',
                'schedule_start' => $request->schedule_start,
                'schedule_end' => $request->schedule_end ?? null,
                'due_date' => $dueDate,
            ]);

            $ticket->ticket_number = $ticket->id;
            $ticket->save();

            TicketLog::create([
                'ticket_id' => $ticket->id, 'user_id' => auth()->id(),
                'action' => 'CREATED', 'description' => 'Tiket layanan baru berhasil dibuat dan menunggu disposisi pimpinan.', 'created_at' => now(),
            ]);

            $opdName = $user->name ?? 'Instansi OPD';
            $categoryLabel = $service->category_label;

            $notifMessage = "📢 *LAYANAN BARU TERSEDIA*\n└─ Instansi: *{$opdName}*\n└─ Layanan: {$categoryLabel}\n└─ Ticket: #{$ticket->ticket_number}\n_Silakan Pimpinan untuk melakukan disposisi._";
            User::where('role', 'pimpinan')->whereNotNull('telegram_chat_id')->each(function($pimpinan) use ($notifMessage) {
                SendTelegramJob::dispatch($notifMessage, $pimpinan->telegram_chat_id);
            });

            return response()->json(['message' => 'Tiket berhasil dibuat', 'data' => $ticket->load(['service', 'requester'])], 201);
        });
    }

public function update(Request $request, $id)
{
    $user = Auth::user();
    $ticket = Ticket::find($id);

    if (!$ticket) {
        return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
    }

    if ($ticket->user_id !== $user->id) {
        return response()->json(['message' => 'Hanya pemohon yang bisa mengubah permohonan ini.'], 403);
    }

    if ($ticket->status === 'cancelled') {
        return response()->json(['message' => 'Tiket yang sudah dibatalkan tidak dapat diubah.'], 403);
    }

    $editableStatuses = ['pending', 'queued', 'needs_reschedule', 'expired'];

    if (!in_array($ticket->status, $editableStatuses)) {
        $pesan = match($ticket->status) {
            'assigned' => 'Formulir layanan tidak dapat diubah karena sudah ditunjuk ke staf pelaksana.',
            'approved_admin' => 'Formulir layanan tidak dapat diubah karena sedang dalam proses persetujuan.',
            'in_progress' => 'Formulir layanan tidak dapat diubah karena sedang dalam proses pengerjaan oleh staf.',
            'completed' => 'Formulir layanan tidak dapat diubah karena layanan sudah selesai.',
            'rejected' => 'Formulir layanan tidak dapat diubah karena permohonan telah ditolak.',
            default => 'Tiket sudah diproses. Perubahan hanya bisa dilakukan melalui ruang diskusi.',
        };

        return response()->json([
            'message' => $pesan,
            'current_status' => $ticket->status
        ], 403);
    }

    $request->validate([
        'form_data' => 'required',
        'schedule_start' => 'nullable|date',
        'schedule_end' => 'nullable|date|after:schedule_start',
        'surat_permohonan' => 'nullable|file|mimes:pdf|max:5120',
        'lampiran_tambahan' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        'due_date' => 'nullable|date',
    ]);

    $formData = $request->form_data;
    if (is_string($formData)) $formData = json_decode($formData, true);
    if (!is_array($formData)) $formData = [];

    $mapKeys = [
        'jumlahPeserta' => 'jumlah_peserta',
        'namaAcara' => 'nama_acara',
        'namaAplikasi' => 'nama_aplikasi',
        'waktuMulai' => 'waktu_mulai',
        'waktuSelesai' => 'waktu_selesai',
        'topik' => 'topik',
        'estimasi' => 'estimasi',
    ];

    foreach ($mapKeys as $camelKey => $snakeKey) {
        if (isset($formData[$camelKey]) && !isset($formData[$snakeKey])) {
            $formData[$snakeKey] = $formData[$camelKey];
            unset($formData[$camelKey]);
        }
    }

    if (isset($formData['nama'])) {
        $nama = trim($formData['nama']);

        if (!preg_match('/^[a-zA-Z\s.\-,\']+$/', $nama)) {
            return response()->json([
                'message' => 'Nama Lengkap tidak valid.',
                'error_field' => 'nama'
            ], 422);
        }

        if (empty($nama)) {
            return response()->json([
                'message' => 'Nama Lengkap wajib diisi.',
                'error_field' => 'nama'
            ], 422);
        }

        $formData['nama'] = $nama;
    }

    $currentService = \App\Models\Service::find($ticket->service_id);
    $category = strtolower($currentService->category ?? '');
    $isScheduleBased = $currentService->is_schedule_based ?? false;

    if ($category === 'command_center') {
        $jumlahPeserta = isset($formData['jumlah_peserta']) ? (int)$formData['jumlah_peserta'] : null;

if (is_null($jumlahPeserta)) {
    return response()->json([
        'message' => 'Kolom Jumlah Peserta wajib diisi untuk layanan Command Center.',
        'error_field' => 'jumlah_peserta'
    ], 422);
}

        if ($jumlahPeserta < 3) {
            return response()->json([
                'message' => 'Jumlah peserta tidak boleh kurang dari 3 orang (kapasitas minimal Command Center).',
                'error_field' => 'jumlah_peserta'
            ], 422);
        }

        if ($jumlahPeserta > 50) {
            return response()->json([
                'message' => 'Jumlah peserta tidak boleh melebihi 50 orang (kapasitas maksimal Command Center).',
                'error_field' => 'jumlah_peserta'
            ], 422);
        }
    }

        if (isset($formData['wa'])) {
            $wa = preg_replace('/\s+/', '', $formData['wa']);

            if ($wa === '') {
                return response()->json([
                    'message' => 'Kolom Nomor WhatsApp tidak valid. Gunakan nomor Indonesia yang benar, contoh: 08123456789.',
                    'error_field' => 'wa'
                ], 422);
            }

        try {
            $phone = new \Propaganistas\LaravelPhone\PhoneNumber($wa, 'ID');

            if (!$phone->isValid()) {
                return response()->json([
                    'message' => 'Nomor WhatsApp tidak valid.',
                    'error_field' => 'wa'
                ], 422);
            }

            $formData['wa'] = $phone->formatE164();

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Format nomor WhatsApp tidak dikenali.',
                'error_field' => 'wa'
            ], 422);
        }
    }

     $formError = $this->validateFormDataByCategory($category, $formData);
    if ($formError) {
        return response()->json($formError, 422);
    }

        if ($category === 'it') {
        $dueDateInput = $request->input('due_date');

        if ($dueDateInput) {
            $parsedDueDate = \Carbon\Carbon::parse($dueDateInput, 'Asia/Makassar')->startOfDay();
            $minDate = \Carbon\Carbon::now('Asia/Makassar')->startOfDay()->addDays(89);

            if ($parsedDueDate->lte($minDate)) {
                return response()->json([
                    'message' => 'Target selesai minimal 3 bulan untuk layanan IT/Website.',
                    'detail' => 'Berdasarkan SOP, pembuatan aplikasi website membutuhkan waktu minimal 3 Bulan (90 Hari).',
                    'error_field' => 'due_date'
                ], 422);
            }

            $ticket->due_date = $parsedDueDate;
        }
    }

    if (!in_array(strtolower($user->role), ['admin', 'staff', 'pimpinan'])) {
        $now = \Carbon\Carbon::now('Asia/Makassar');

        if ($now->isWeekend()) {
            return response()->json([
                'message' => 'Layanan hanya dapat diajukan pada hari Senin sampai Jumat.',
                'detail' => 'Hari ini adalah hari ' . $now->locale('id')->format('l') . '. Pengajuan layanan hanya bisa dilakukan pada hari kerja.',
            ], 422);
        }

        if ($request->has('schedule_start')) {
            $checkStart = \Carbon\Carbon::parse($request->schedule_start, 'Asia/Makassar');
            if ($checkStart->isWeekend()) {
                $hariList = ['Sunday' => 'Minggu', 'Saturday' => 'Sabtu'];
                $hariId = $hariList[$checkStart->format('l')] ?? $checkStart->format('l');

                return response()->json([
                    'message' => 'Jadwal layanan hanya tersedia hari Senin - Jumat.',
                    'detail' => 'Hari yang Anda pilih adalah hari ' . $hariId . '. Silakan pilih hari kerja.',
                    'error_field' => 'schedule_start'
                ], 422);
            }
        }
    }

    if ($isScheduleBased) {
        $scheduleStartValue = $request->has('schedule_start') ? $request->schedule_start : $ticket->schedule_start;
        $scheduleEndValue = $request->has('schedule_end') ? $request->schedule_end : $ticket->schedule_end;

        if ($scheduleStartValue && $scheduleEndValue) {
            $newStart = \Carbon\Carbon::parse($scheduleStartValue, 'Asia/Makassar');
            $newEnd = \Carbon\Carbon::parse($scheduleEndValue, 'Asia/Makassar');
            $nowWita = \Carbon\Carbon::now('Asia/Makassar');

            if ($newStart->lt($nowWita)) {
                return response()->json([
                    'message' => 'Tidak dapat melakukan pemesanan untuk jadwal yang sudah lewat.',
                    'detail' => 'Waktu yang Anda pilih (' . $newStart->format('d F Y, H.i') . ' WITA) sudah berlalu.',
                    'error_field' => 'schedule_start'
                ], 422);
            }

            $durasiJam = $newStart->diffInMinutes($newEnd) / 60;
            $layananLabel = $category === 'zoom' ? 'Zoom' : 'Command Center';

            if ($durasiJam > 6) {
                return response()->json([
                    'message' => 'Durasi pemesanan ' . $layananLabel . ' melebihi batas maksimal.',
                    'detail' => 'Berdasarkan SOP, durasi maksimal pemesanan ' . $layananLabel . ' adalah 6 jam. Anda mengajukan durasi ' . round($durasiJam, 1) . ' jam.',
                    'error_field' => 'schedule_end',
                    'max_duration' => 6,
                    'current_duration' => round($durasiJam, 1)
                ], 422);
            }

            if ($category === 'command_center') {
                if ($newStart->format('H:i') < '07:30') {
                    return response()->json([
                        'message' => 'Jam mulai pemesanan Command Center di luar jam operasional.',
                        'detail' => 'Jam operasional Command Center dimulai pukul 07.30 WITA. Anda memilih jam ' . $newStart->format('H.i') . ' WITA.',
                        'error_field' => 'schedule_start',
                        'min_time' => '07:30'
                    ], 422);
                }

                if ($newEnd->format('H:i') > '16:00') {
                    return response()->json([
                        'message' => 'Jam selesai pemesanan Command Center di luar jam operasional.',
                        'detail' => 'Jam operasional Command Center berakhir pukul 16.00 WITA. Anda memilih jam ' . $newEnd->format('H.i') . ' WITA.',
                        'error_field' => 'schedule_end',
                        'max_time' => '16:00'
                    ], 422);
                }

                $isConflict = Ticket::where('id', '!=', $ticket->id)
                    ->whereHas('service', function ($q) {
                        $q->where('category', 'command_center');
                    })
                    ->whereIn('status', ['pending', 'assigned', 'in_progress', 'approved_admin'])
                    ->whereNotNull('schedule_start')
                    ->whereNotNull('schedule_end')
                    ->where(function ($query) use ($newStart, $newEnd) {
                        $query->where('schedule_start', '<', $newEnd)
                              ->where('schedule_end', '>', $newStart);
                    })->exists();

if ($isConflict) {
    return response()->json([
        'message' => 'Jadwal yang dipilih bentrok dengan booking Command Center lain pada tanggal dan jam yang sama. Silakan pilih jadwal lain.',
        'error_field' => 'schedule_start'
    ], 422);
}
            }

            if ($category === 'zoom') {
                $totalLinks = \App\Models\ZoomLink::count();

                if ($totalLinks === 0) {
                    return response()->json([
                        'message' => 'Tidak ada link Zoom tersedia pada sistem. Silakan hubungi Admin.',
                        'error_field' => 'schedule_start'
                    ], 422);
                }

                $totalAvailableLinks = \App\Models\ZoomLink::where('status', 'available')->count();

                if ($totalAvailableLinks === 0) {
                    return response()->json([
                        'message' => 'Tidak ada link Zoom tersedia pada jam tersebut.',
                        'detail' => 'Semua link Zoom (' . $totalLinks . ') sedang digunakan oleh layanan lain.',
                        'error_field' => 'schedule_start',
                        'total_links' => $totalLinks,
                        'available_links' => 0
                    ], 422);
                }

                $bookingDiJadwal = Ticket::where('id', '!=', $ticket->id)
                    ->whereHas('service', function ($q) {
                        $q->where('category', 'zoom');
                    })
                    ->whereIn('status', ['pending', 'assigned', 'in_progress', 'approved_admin'])
                    ->whereNotNull('schedule_start')
                    ->whereNotNull('schedule_end')
                    ->where(function ($query) use ($newStart, $newEnd) {
                        $query->where('schedule_start', '<', $newEnd)
                              ->where('schedule_end', '>', $newStart);
                    })
                    ->count();

                $sisaLink = $totalAvailableLinks - $bookingDiJadwal;

                if ($sisaLink <= 0) {
                    return response()->json([
                        'message' => 'Jadwal bentrok dengan booking Zoom lain.',
                        'detail' => 'Semua link Zoom pada jam tersebut sudah dipesan (' . $bookingDiJadwal . ' booking, ' . $totalAvailableLinks . ' link tersedia).',
                        'error_field' => 'schedule_start'
                    ], 422);
                }
            }
        }
    }

    if ($request->hasFile('surat_permohonan')) {
        if ($ticket->surat_permohonan_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($ticket->surat_permohonan_path);
        }
        $ticket->surat_permohonan_path = $request->file('surat_permohonan')->store('surat_permohonan', 'local');
    }

    if ($request->hasFile('lampiran_tambahan')) {
        if ($ticket->lampiran_tambahan_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($ticket->lampiran_tambahan_path);
        }
        $ticket->lampiran_tambahan_path = $request->file('lampiran_tambahan')->store('lampiran_tambahan', 'local');
    }

    $ticket->form_data = $formData;

    if ($request->has('schedule_start')) {
        $ticket->schedule_start = $request->schedule_start;
    }

    if ($request->has('schedule_end')) {
        $ticket->schedule_end = $request->schedule_end;
    }

    if (($ticket->status === 'needs_reschedule' || $ticket->status === 'expired') && ($ticket->isDirty('schedule_start') || $ticket->isDirty('schedule_end'))) {
        $ticket->status = 'pending';
        $ticket->is_sla_notified = false;
        $ticket->overdue_notified_at = null;

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'action' => 'RESCHEDULED',
            'description' => 'OPD mengubah jadwal pelaksanaan dan tiket dikembalikan ke antrian.',
            'created_at' => now(),
        ]);
    }

    try {
        $ticket->save();
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('Gagal update tiket', [
            'ticket_id' => $ticket->id,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'message' => 'Gagal menyimpan perubahan. Silakan coba lagi.'
        ], 500);
    }

    $ticket->refresh();

    return response()->json([
        'message' => 'Permohonan berhasil diperbarui',
        'data' => $ticket->load(['service', 'staff', 'requester', 'zoomLink'])
    ]);
}

public function updateStatus(Request $request, $detail_id)
{
    $rules = [
        'status' => 'required|in:pending,queued,approved_admin,assigned,in_progress,completed,rejected,cancelled,expired,overdue_schedule,needs_reschedule'
    ];

    if ($request->status === 'rejected') {
        $rules['rejection_reason'] = 'required|string|max:500';
    }

    $request->validate($rules);

    $user = Auth::user();
    $ticket = Ticket::with(['service', 'staff', 'requester', 'zoomLink'])->find($detail_id);

    if (!$ticket) {
        return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
    }

    $oldStatus = $ticket->status;

    if ($request->status === 'completed' && !in_array($ticket->status, ['in_progress', 'assigned', 'approved_admin'])) {
        return response()->json([
            'message' => 'Tugas belum dapat diselesaikan karena belum dimulai.',
            'error_field' => 'status'
        ], 422);
    }

    if ($request->status === 'completed') {
        $category = strtolower($ticket->service->category ?? '');

        if ($category === 'zoom') {
            $hasZoomLink = $ticket->zoomLink && !empty($ticket->zoomLink->link);

            if (!$hasZoomLink) {
                return response()->json([
                    'message' => 'Tugas Zoom belum dapat diselesaikan karena link Zoom belum ditetapkan.',
                    'error_field' => 'zoom_link'
                ], 422);
            }
        }
    }

    if ($request->status === 'cancelled' && $user->role === 'opd') {
        if ($ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }
        if (!in_array($ticket->status, ['pending', 'queued'])) {
            return response()->json(['message' => 'Gagal membatalkan.'], 403);
        }

        if ($ticket->zoom_link_id) {
            ZoomLink::where('id', $ticket->zoom_link_id)->update([
                'status' => 'available',
                'used_by_ticket_id' => null
            ]);
            $ticket->zoom_link_id = null;
        }

        $ticket->status = 'cancelled';
        $ticket->save();

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'action' => 'CANCELLED',
            'description' => 'Tiket dibatalkan oleh pemohon.',
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Permohonan berhasil dibatalkan', 'data' => $ticket->load('zoomLink')]);
    }

    if ($user->role === 'admin') {
        return response()->json(['message' => 'Akses ditolak.'], 403);
    }

    if ($user->role === 'staff' && $ticket->assigned_staff_id !== $user->id) {
        return response()->json(['message' => 'Akses ditolak.'], 403);
    }

    if ($request->status === 'completed') {
        $isScheduleBased = $ticket->service->is_schedule_based;

        if ($isScheduleBased && $ticket->schedule_start) {
            $now = \Carbon\Carbon::now('Asia/Makassar');
            $startTime = \Carbon\Carbon::parse($ticket->schedule_start, 'Asia/Makassar');
            if ($now->lt($startTime)) {
                return response()->json(['message' => 'Layanan belum dimulai dan belum dapat diselesaikan.'], 422);
            }
        }
        $ticket->completed_at = now();
    }

    if (in_array($request->status, ['completed', 'rejected', 'cancelled', 'expired']) && $ticket->zoom_link_id) {
        ZoomLink::where('id', $ticket->zoom_link_id)->update([
            'status' => 'available',
            'used_by_ticket_id' => null
        ]);
        $ticket->zoom_link_id = null;
    }

    $ticket->status = $request->status;

    if ($request->status === 'rejected') {
        $ticket->rejection_reason = $request->rejection_reason;
    }

    $ticket->save();

    if ($request->status === 'in_progress') {
        TicketLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'action' => 'IN_PROGRESS',
            'description' => 'Staff memulai pengerjaan tiket.',
            'created_at' => now(),
        ]);
    }

    if ($request->status === 'completed') {
        TicketLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'action' => 'COMPLETED',
            'description' => 'Staff menyelesaikan tiket layanan.',
            'created_at' => now(),
        ]);

        $opdChatId = $ticket->requester->telegram_chat_id ?? null;
        SendTelegramJob::dispatch(
            "✅ *Layanan Telah Selesai*\n━━━━━━━━━━━━━━━━━━━\nTicket : #{$ticket->ticket_number}\n━━━━━━━━━━━━━━━━━━━\n_Silakan membuka Website untuk melihat detail layanan dan mengisi Survei Kepuasan Masyarakat (SKM)._",
            $opdChatId
        );
    }

    if ($request->status === 'rejected') {
        TicketLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'action' => 'REJECTED',
            'description' => 'Disposisi ditolak oleh Pimpinan. Alasan: ' . $request->rejection_reason,
            'created_at' => now(),
        ]);

        $opdChatId = $ticket->requester->telegram_chat_id ?? null;

        if ($opdChatId) {
            $zoomText = "";
            if ($ticket->zoom_link_id && $ticket->zoomLink) {
                $zoomText = "Link Zoom: " . $ticket->zoomLink->link . "\n";
            }

            SendTelegramJob::dispatch(
                "✅ *Layanan Anda Telah Diterima*\n━━━━━━━━━━━━━━━━━━━\nTicket : #{$ticket->ticket_number}\nStaff  : *{$user->name}*\nStatus : Sedang Diproses\n{$zoomText}━━━━━━━━━━━━━━━━━━━\n",
                $opdChatId
            );
        }
    }

    if ($oldStatus !== $request->status && !in_array($request->status, ['in_progress', 'completed', 'rejected'])) {
        TicketLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'action' => strtoupper($request->status),
            'description' => "Status tiket diubah dari '{$oldStatus}' menjadi '{$request->status}'.",
            'created_at' => now(),
        ]);
    }

    return response()->json(['message' => 'Status tiket berhasil diperbarui', 'data' => $ticket->load('zoomLink')]);
    }

    public function processByStaff(Request $request, $id)
    {
        $user = Auth::user();
        $ticket = Ticket::with('service')->find($id);
        
        if (!$ticket || $ticket->assigned_staff_id !== $user->id) {
            return response()->json(['message' => 'Akses ditolak. Bukan tiket Anda.'], 403);
        }

        $request->validate([
            'action' => 'required|in:approve,reject',
            'rejection_reason' => 'required_if:action,reject|string|max:500',
            'zoom_link_id' => 'nullable|exists:zoom_links,id'
        ]);

        if ($request->action === 'reject') {
            if ($ticket->zoom_link_id) {
                ZoomLink::where('id', $ticket->zoom_link_id)->update([
                    'status' => 'available', 
                    'used_by_ticket_id' => null
                ]);
                $ticket->zoom_link_id = null;
            }

            $ticket->status = 'rejected';
            $ticket->rejection_reason = $request->rejection_reason;
            $ticket->assigned_staff_id = null; 
            $ticket->save();

            TicketLog::create([
                'ticket_id' => $ticket->id, 
                'user_id' => auth()->id(),
                'action' => 'REJECTED', 
                'description' => "Staff menolak. Alasan: {$request->rejection_reason}", 
                'created_at' => now(),
            ]);

            $opdChatId = $ticket->requester->telegram_chat_id ?? null;
            if ($opdChatId) {
                SendTelegramJob::dispatch(
                    "❌ *Layanan Ditolak*\n━━━━━━━━━━━━━━━━━━━\nTicket : #{$ticket->ticket_number}\nAlasan : {$request->rejection_reason}\n━━━━━━━━━━━━━━━━━━━\n", 
                    $opdChatId
                );
            }

            // ✅ FIX: Tambahkan zoomLink saat return
            return response()->json(['message' => 'Layanan berhasil ditolak.', 'data' => $ticket->load('zoomLink')]);
        }

        if ($request->action === 'approve') {
            if (strtolower($ticket->service->category) === 'zoom') {
                if (!$request->zoom_link_id) {
                    return response()->json(['message' => 'Wajib memilih link zoom untuk layanan ini.'], 422);
                }

                $zoomLink = ZoomLink::where('id', $request->zoom_link_id)
                    ->where('status', 'available')
                    ->lockForUpdate()
                    ->first();

                if (!$zoomLink) {
                    return response()->json(['message' => 'Link zoom sudah dipakai layanan lain atau tidak ditemukan. Pilih link lain.'], 422);
                }

                $ticket->zoom_link_id = $zoomLink->id;
                $zoomLink->update(['status' => 'in_use', 'used_by_ticket_id' => $ticket->id]);
            }

            $ticket->status = 'in_progress'; 
            $ticket->save();

            TicketLog::create([
                'ticket_id' => $ticket->id, 
                'user_id' => auth()->id(),
                'action' => 'IN_PROGRESS', 
                'description' => 'Staff menerima dan memulai pengerjaan.', 
                'created_at' => now(),
            ]);

            $opdChatId = $ticket->requester->telegram_chat_id ?? null;
            if ($opdChatId && $ticket->zoom_link_id) {
                // Pakai relasi zoomLink yang udah di-load, bukan variabel lokal
                $zoomText = $ticket->zoomLink ? $ticket->zoomLink->link : 'Tidak tersedia';
                SendTelegramJob::dispatch(
                    "✅ *Layanan Anda Telah Diterima*\n━━━━━━━━━━━━━━━━━━━\nTicket : #{$ticket->ticket_number}\nLink Zoom: {$zoomText}\n━━━━━━━━━━━━━━━━━━━\n", 
                    $opdChatId
                );
            }

            return response()->json(['message' => 'Layanan diterima dan sedang dikerjakan.', 'data' => $ticket->load('zoomLink')]);
        }
    }

    public function previewPdf($id)
    {
        $user = Auth::user();

        $ticket = Ticket::with(['service', 'staff', 'requester', 'zoomLink'])->find($id);
        if (!$ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        $allowed = in_array($user->role, ['admin', 'pimpinan'])
            || $ticket->user_id === $user->id
            || $ticket->assigned_staff_id === $user->id;

        if (!$allowed) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak berhak mengakses dokumen tiket ini.'], 403);
        }

        return Pdf::loadView('pdf.bukti-layanan', compact('ticket'))
            ->setPaper('A4', 'portrait')
            ->stream('Bukti_Layanan_Ticket_' . $ticket->ticket_number . '.pdf');
    }

    public function downloadPdf($id)
    {
        $user = Auth::user();

        $ticket = Ticket::with(['service', 'staff', 'requester', 'zoomLink'])->find($id);
        if (!$ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        $allowed = in_array($user->role, ['admin', 'pimpinan'])
            || $ticket->user_id === $user->id
            || $ticket->assigned_staff_id === $user->id;

        if (!$allowed) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak berhak mengakses dokumen tiket ini.'], 403);
        }

        return Pdf::loadView('pdf.bukti-layanan', compact('ticket'))
            ->setPaper('A4', 'portrait')
            ->download('Bukti_Layanan_Ticket_' . $ticket->ticket_number . '.pdf');
    }

public function exportWord($id)
{
    $ticket = Ticket::with(['service', 'staff', 'requester', 'zoomLink'])->find($id);

    if (!$ticket) {
        return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
    }

     $user = Auth::user();

    $allowed = in_array($user->role, ['admin', 'pimpinan'])
        || $ticket->user_id === $user->id
        || $ticket->assigned_staff_id === $user->id;

    if (!$allowed) {
        return response()->json(['message' => 'Akses ditolak. Anda tidak berhak mengakses dokumen tiket ini.'], 403);
    }

    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $phpWord->setDefaultFontName('Times New Roman');
    $phpWord->setDefaultFontSize(12);

    $section = $phpWord->addSection([
        'pageSizeW'    => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(21),
        'pageSizeH'    => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(29.7),
        'marginTop'    => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2),
        'marginRight'  => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.5),
        'marginBottom' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2),
        'marginLeft'   => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.5),
    ]);

    // ========== FONT ONLY ==========
    $f12 = ['name' => 'Times New Roman', 'size' => 12];
    $f10 = ['name' => 'Times New Roman', 'size' => 10];
    $f9  = ['name' => 'Times New Roman', 'size' => 9];
    $f12b = ['name' => 'Times New Roman', 'size' => 12, 'bold' => true];
    $f15b = ['name' => 'Times New Roman', 'size' => 15, 'bold' => true];
    $f13b = ['name' => 'Times New Roman', 'size' => 13, 'bold' => true];
    $f14bu = ['name' => 'Times New Roman', 'size' => 14, 'bold' => true, 'underline' => 'single'];

    // ========== HELPER ==========
    $addRow = function ($table, $label, $value) use ($f12, $f12b) {
        $table->addRow();
        $c1 = $table->addCell(3500, ['valign' => 'top']);
        $c1->addText($label, $f12b);
        $c2 = $table->addCell(500, ['valign' => 'top']);
        $c2->addText(':', $f12, ['alignment' => 'center']);
        $c3 = $table->addCell(6000, ['valign' => 'top']);
        $c3->addText($value, $f12);
    };

    // ========== KOP SURAT ==========
    $logoPemerintah = public_path('images/logo-pemerintah.png');
    $logoKominfo = public_path('images/logo-kominfo.png');

    $kopTable = $section->addTable(['width' => 100, 'unit' => 'pct', 'cellMargin' => 0]);
    $kopTable->addRow();

    // Logo Kiri
    $cellKiri = $kopTable->addCell(2200, ['valign' => 'center']);
    if (file_exists($logoPemerintah)) {
        $cellKiri->addImage($logoPemerintah, ['width' => 75, 'height' => 75]);
    }

    // Tengah
    $cellTengah = $kopTable->addCell(6100, ['valign' => 'center']);
    $cellTengah->addText('PEMERINTAH KOTA BONTANG', $f15b, ['alignment' => 'center', 'spaceAfter' => 0]);
    $cellTengah->addText('DINAS KOMUNIKASI DAN INFORMATIKA', $f13b, ['alignment' => 'center', 'spaceAfter' => 60]);
    $cellTengah->addText('Jl. Brigjen Katamso No. 1, Bontang Utara, Kota Bontang, Kalimantan Timur', $f9, ['alignment' => 'center', 'spaceAfter' => 0]);
    $cellTengah->addText('Telp: (0548) 22222 | Website: kominfo.bontangkota.go.id', $f9, ['alignment' => 'center', 'spaceAfter' => 0]);

    // Logo Kanan
    $cellKanan = $kopTable->addCell(2200, ['valign' => 'center']);
    if (file_exists($logoKominfo)) {
        $cellKanan->addImage($logoKominfo, ['width' => 75, 'height' => 75]);
    }

    // Garis Kop Surat (Diubah menjadi 1 garis tebal sedang)
    $section->addText('', $f12, ['borderBottomSize' => 12, 'borderBottomColor' => '000000', 'spaceAfter' => 200]);

    // ========== JUDUL ==========
    $section->addText('BUKTI PENERIMAAN LAYANAN', $f14bu, ['alignment' => 'center', 'spaceAfter' => 80]);
    $nomorSurat = $ticket->id . '/KOMINFO/' . date('m/Y', strtotime($ticket->created_at));
    $section->addText('Nomor: ' . $nomorSurat, $f10, ['alignment' => 'center', 'spaceAfter' => 200]);

    // ========== PEMBUKA ==========
    $section->addText(
        'Yang bertanda tangan di bawah ini, Kepala Dinas Komunikasi dan Informatika Kota Bontang dengan ini menerangkan bahwa telah menerima permohonan layanan dari:',
        $f12,
        ['alignment' => 'both', 'spaceAfter' => 150, 'indentation' => ['left' => 720]]
    );

    // ========== DATA ==========
    $dataTable = $section->addTable(['width' => 100, 'unit' => 'pct', 'cellMargin' => 50, 'indentation' => ['left' => 580]]);

    $addRow($dataTable, 'Nama Pemohon', $ticket->requester->name ?? 'N/A');
    $addRow($dataTable, 'Email / OPD', $ticket->requester->email ?? '-');
    $addRow($dataTable, 'Jenis Layanan', $ticket->service->name ?? 'N/A');
    
    // Tanggal (Bahasa Indonesia menggunakan isoFormat)
    $tanggalPermohonan = \Carbon\Carbon::parse($ticket->created_at)->locale('id')->isoFormat('dddd, D MMMM Y');
    $addRow($dataTable, 'Hari / Tanggal', $tanggalPermohonan);

    if ($ticket->schedule_start) {
        $jadwalMulai = \Carbon\Carbon::parse($ticket->schedule_start)->locale('id')->isoFormat('D MMMM Y, H:i');
        $jadwalSelesai = \Carbon\Carbon::parse($ticket->schedule_end)->format('H:i');
        $jadwal = $jadwalMulai . ' s.d ' . $jadwalSelesai . ' WITA';
        $addRow($dataTable, 'Jadwal Layanan', $jadwal);
    }

    $addRow($dataTable, 'Pelaksana Staf', $ticket->staff->name ?? 'Belum Ditugaskan');
    $addRow($dataTable, 'Status', strtoupper($ticket->status));

    $section->addText('', $f12, ['spaceAfter' => 150]);

    // ========== DETAIL ==========
    $section->addText(
        'Adapun keterangan detail permohonan yang diajukan adalah sebagai berikut:',
        $f12,
        ['spaceAfter' => 80, 'indentation' => ['left' => 580]]
    );

    if ($ticket->form_data && count($ticket->form_data) > 0) {
        $detailTable = $section->addTable([
            'borderSize' => 6,
            'borderColor' => '000000',
            'width' => 95,
            'unit' => 'pct',
            'cellMargin' => 80,
            'indentation' => ['left' => 580]
        ]);

        foreach ($ticket->form_data as $key => $value) {
            if ($key === 'wa') continue;

            $label = ucfirst(str_replace('_', ' ', $key));
            $val = is_array($value) ? implode(', ', $value) : $value;

            $detailTable->addRow();
            $detailTable->addCell(4000, ['bgColor' => 'F5F5F5', 'valign' => 'center'])->addText($label, $f12b);
            $detailTable->addCell(6000, ['valign' => 'center'])->addText($val, $f12);
        }
    }

    $section->addText('', $f12, ['spaceAfter' => 100]);

    // ========== PENUTUP ==========
    $section->addText(
        'Surat bukti ini dibuat secara otomatis oleh sistem SIKOMA Dinas Komunikasi dan Informatika Kota Bontang untuk dapat dipergunakan sebagaimana mestinya.',
        $f12,
        ['alignment' => 'both', 'spaceAfter' => 250, 'indentation' => ['left' => 720]]
    );

    // ========== TTD ==========
    $ttdTable = $section->addTable(['width' => 100, 'unit' => 'pct', 'cellMargin' => 100]);
    $ttdTable->addRow();

    $tanggalSurat = \Carbon\Carbon::now()->locale('id')->isoFormat('D MMMM Y');

    // Staff
    $s = $ttdTable->addCell(5000, ['valign' => 'top']);
    $s->addText('Bontang, ' . $tanggalSurat, $f10, ['alignment' => 'center', 'spaceAfter' => 0]);
    $s->addText('Pelaksana Layanan,', $f10, ['alignment' => 'center', 'spaceAfter' => 0]);
    $s->addText('', $f12, ['spaceAfter' => 900]);
    $s->addText('________________________', $f10, ['alignment' => 'center', 'spaceAfter' => 20]);
    $s->addText($ticket->staff->name ?? 'Belum Ditugaskan', $f12b, ['alignment' => 'center', 'spaceAfter' => 0]);
    $s->addText('NIP. ' . ($ticket->staff->nip ?? '................................'), $f10, ['alignment' => 'center', 'spaceAfter' => 0]);

    // Kadis
    $k = $ttdTable->addCell(5000, ['valign' => 'top']);
    $k->addText('Bontang, ' . $tanggalSurat, $f10, ['alignment' => 'center', 'spaceAfter' => 0]);
    $k->addText('Kepala Dinas Kominfo,', $f10, ['alignment' => 'center', 'spaceAfter' => 0]);
    $k->addText('', $f12, ['spaceAfter' => 900]);
    $k->addText('________________________', $f10, ['alignment' => 'center', 'spaceAfter' => 20]);
    $k->addText('________________________', $f12b, ['alignment' => 'center', 'spaceAfter' => 0]);
    $k->addText('NIP. ................................', $f10, ['alignment' => 'center', 'spaceAfter' => 0]);

    // ========== DOWNLOAD ==========
    $fileName = 'Bukti_Layanan_Ticket_' . $ticket->ticket_number . '.docx';
    $tempFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'Bukti_Layanan_Ticket_' . $ticket->ticket_number . '_' . uniqid('', true) . '.docx';

    $phpWord->save($tempFilePath, 'Word2007');

    return response()->download($tempFilePath)->deleteFileAfterSend(true);
}

    public function exportExcelBukti($id)
    {
    $ticket = Ticket::with(['service', 'staff', 'requester'])->find($id);
    if (!$ticket) return response()->json(['message' => 'Tiket tidak ditemukan'], 404);

     $user = Auth::user();

    $allowed = in_array($user->role, ['admin', 'pimpinan'])
        || $ticket->user_id === $user->id
        || $ticket->assigned_staff_id === $user->id;

    if (!$allowed) {
        return response()->json(['message' => 'Akses ditolak. Anda tidak berhak mengakses dokumen tiket ini.'], 403);
    }

    return Excel::download(new \App\Exports\BuktiLayananExport($ticket), 'Bukti_Layanan_Ticket_' . $ticket->ticket_number . '.xlsx');
    }

    /**
 * Preview file dengan Content-Disposition: inline
 * Untuk digunakan di iframe agar tidak force download
 */
    // ✅ FITUR BARU: File Preview yang aman dari IDOR
    public function previewFile($path)
    {
        $user = Auth::user();

        $ticket = Ticket::where('surat_permohonan_path', $path)
            ->orWhere('lampiran_tambahan_path', $path)
            ->first();

        if (!$ticket) {
            $comment = \App\Models\TicketComment::where('file_path', $path)->first();
            if ($comment) {
                $ticket = $comment->ticket;
            }
        }

        if (!$ticket) {
            return response()->json(['message' => 'File tidak ditemukan atau tidak terkait tiket manapun.'], 404);
        }

        $allowed = in_array($user->role, ['admin', 'pimpinan'])
            || $ticket->user_id === $user->id
            || $ticket->assigned_staff_id === $user->id;

        if (!$allowed) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak berhak mengakses file ini.'], 403);
        }

        if (!Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'File rusak atau sudah dihapus dari server.'], 404);
        }

        return Storage::disk('local')->response($path);
    }

    private function validateFormDataByCategory($category, $formData)
    {
        $required = [
            'it' => ['nama_aplikasi', 'tujuan_pembuatan'],
            'zoom' => ['topik_meeting', 'tanggal', 'waktu_mulai', 'waktu_selesai'],
            'command_center' => ['nama_acara', 'tanggal', 'waktu_mulai', 'waktu_selesai'],
        ];

        foreach ($required[$category] ?? [] as $field) {
            $value = $formData[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                return [
                    'message' => 'Kolom "' . ucfirst(str_replace('_', ' ', $field)) . '" wajib diisi.',
                    'error_field' => $field,
                ];
            }
        }

        return null;
    }

    public function getResubmitData($id)
{
    $user = Auth::user();

    $ticket = Ticket::with('service')->find($id);

    if (!$ticket) {
        return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
    }

    if ($ticket->user_id !== $user->id && !in_array($user->role, ['admin', 'pimpinan'])) {
        return response()->json(['message' => 'Akses ditolak. Anda tidak berhak mengakses data tiket ini.'], 403);
    }

    if ($ticket->status !== 'expired') {
        return response()->json(['message' => 'Data pengajuan ulang hanya tersedia untuk tiket berstatus Expired.'], 403);
    }

    if ($ticket->resubmitted_at !== null) {
        return response()->json(['message' => 'Tiket ini sudah pernah diajukan ulang dan terkunci.'], 403);
    }

    return response()->json([
        'message' => 'Data permohonan berhasil diambil',
        'data' => [
            'service_id' => $ticket->service_id,
            'form_data' => $ticket->form_data ?? [],
        ],
    ]);
}
}