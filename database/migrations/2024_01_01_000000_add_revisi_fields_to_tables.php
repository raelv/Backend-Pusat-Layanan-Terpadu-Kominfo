<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tambahan ke Tabel Users
        Schema::table('users', function (Blueprint $table) {
            // Pastikan kolom role bisa menampung 'pimpinan' (Jika pakai enum, ubah ke string)
            // Jika awalnya sudah string, ini tidak akan error
            $table->string('role', 20)->default('opd')->change(); 
        });

        // 2. Buat Tabel Zoom Links (3 Link Blok Zoom)
        Schema::create('zoom_links', function (Blueprint $table) {
            $table->id();
            $table->string('title'); // Contoh: "Ruang Zoom 1"
            $table->string('link')->unique();
            $table->enum('status', ['available', 'in_use'])->default('available');
            $table->foreignId('used_by_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->timestamps();
        });

        // 3. Tambahan ke Tabel Tickets
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('zoom_link_id')->nullable()->constrained()->nullOnDelete(); // Link zoom yg dipilih staff
            $table->text('rejection_reason')->nullable(); // Alasan staff menolak
            $table->string('assigned_by_role')->nullable(); // 'pimpinan' atau 'admin'
            $table->timestamp('disposed_at')->nullable(); // Waktu disposisi (untuk hitung 24 jam)
            $table->timestamp('escalated_at')->nullable(); // Waktu naik ke admin (lebih 24 jam)
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['zoom_link_id']);
            $table->dropColumn(['zoom_link_id', 'rejection_reason', 'assigned_by_role', 'disposed_at', 'escalated_at']);
        });
        Schema::dropIfExists('zoom_links');
    }
};