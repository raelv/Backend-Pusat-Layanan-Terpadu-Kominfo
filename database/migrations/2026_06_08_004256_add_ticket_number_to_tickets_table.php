<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB; // Tambahkan ini

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Tambah kolom tapi BOLEH KOSONG (nullable) dulu
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('ticket_number')->nullable()->after('id');
        });

        // Step 2: Isi tiket lama yang kosong dengan "Legacy-ID-{id}" supaya tidak ada yang duplikat
        DB::statement("UPDATE tickets SET ticket_number = CONCAT('Legacy-ID-', id) WHERE ticket_number IS NULL");

        // Step 3: Ubah kolom menjadi WAJIB DIISI (Not Null)
        DB::statement("ALTER TABLE tickets ALTER COLUMN ticket_number SET NOT NULL");

        // Step 4: Baru kita kasih aturan Unik
        Schema::table('tickets', function (Blueprint $table) {
            $table->unique('ticket_number');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('ticket_number');
        });
    }
};