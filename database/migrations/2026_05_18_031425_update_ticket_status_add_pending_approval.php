<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
        public function up(): void
    {
        // Hapus aturan lama
        \DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_status_check');

        // Buat aturan baru termasuk 'pending_approval'
        \DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('pending', 'queued', 'approved_admin', 'assigned', 'in_progress', 'completed', 'rejected', 'cancelled', 'pending_approval'))");
    }

    public function down(): void
    {
        // Rollback (kembalikan ke lama)
        \DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_status_check');
        \DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('pending', 'queued', 'approved_admin', 'assigned', 'in_progress', 'completed', 'rejected', 'cancelled'))");
    }
};
