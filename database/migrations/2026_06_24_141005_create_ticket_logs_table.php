<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            
            // Bisa null karena bisa saja aksi dilakukan oleh Sistem/Cron Job
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            
            $table->string('action'); // Contoh: created, assigned, completed, reminder_sent
            $table->text('description'); // Contoh: "Staf Budi mengambil tiket"
            
            // Optional: Simpan data lama/baru (berguna kalau mau bikin fitur undo atau audit detail)
            $table->json('properties')->nullable();
            
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_logs');
    }
};