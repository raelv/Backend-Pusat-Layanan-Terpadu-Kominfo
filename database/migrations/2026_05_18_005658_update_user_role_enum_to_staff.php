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
        // 1. Hapus aturan lama (Constraint) yang bermasalah
        \DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');

        // 2. Buat aturan baru dengan nilai 'staff' (tanpa a)
        \DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'staff', 'opd'))");
    }

    public function down(): void
    {
        // Fungsi rollback (jika perlu kembali ke kondisi awal)
        \DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        \DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'staf', 'opd'))");
    }
};
