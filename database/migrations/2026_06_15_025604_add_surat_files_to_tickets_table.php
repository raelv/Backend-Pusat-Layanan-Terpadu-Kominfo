<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('surat_permohonan_path')->nullable()->after('form_data'); // Wajib
            $table->string('lampiran_tambahan_path')->nullable()->after('surat_permohonan_path'); // Opsional
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['surat_permohonan_path', 'lampiran_tambahan_path']);
        });
    }
};