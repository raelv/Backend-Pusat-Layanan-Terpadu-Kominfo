<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ============================================
        // 1. TAMBAH KOLOM CATEGORY DI SERVICES
        // ============================================
        Schema::table('services', function (Blueprint $table) {
            $table->string('category', 30)->default('it')->after('slug');
        });

        // ============================================
        // 2. TAMBAH STATUS BARU DI TICKETS (PostgreSQL)
        // ============================================
        $enumName = 'tickets_status_enum';
        
        $enumExists = DB::selectOne("
            SELECT typname 
            FROM pg_type 
            WHERE typname = '{$enumName}'
        ");

        if ($enumExists) {
            DB::statement("ALTER TYPE {$enumName} ADD VALUE IF NOT EXISTS 'pending_opd_approval'");
            DB::statement("ALTER TYPE {$enumName} ADD VALUE IF NOT EXISTS 'needs_reschedule'");
        }
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};