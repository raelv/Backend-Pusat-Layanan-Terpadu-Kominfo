<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SecureTicketFiles extends Command
{
    protected $signature = 'tickets:secure-files';
    protected $description = 'Pindahkan semua file tiket dari disk publik ke disk privat (storage/app/private)';

    public function handle()
    {
        $from = Storage::disk('public');
        $to = Storage::disk('local');

        $folders = ['surat_permohonan', 'lampiran_tambahan', 'comments'];

        $moved = 0;
        $synced = 0;
        $failed = 0;

        foreach ($folders as $folder) {
            if (!$from->exists($folder)) {
                $this->info("Folder {$folder}: tidak ada di disk publik (ok)");
                continue;
            }

            foreach ($from->allFiles($folder) as $path) {
                try {
                    if ($to->exists($path)) {
                        $from->delete($path);
                        $synced++;
                        continue;
                    }

                    $stream = $from->readStream($path);
                    $to->writeStream($path, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    if ($to->exists($path)) {
                        $from->delete($path);
                        $moved++;
                    } else {
                        $failed++;
                        $this->error("GAGAL pindah: {$path}");
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("ERROR {$path}: {$e->getMessage()}");
                }
            }

            if (empty($from->allFiles($folder))) {
                $from->deleteDirectory($folder);
            }
        }

        $this->info("Selesai. Dipindahkan: {$moved}, sudah-ada: {$synced}, gagal: {$failed}");

        return $failed > 0 ? 1 : 0;
    }
}