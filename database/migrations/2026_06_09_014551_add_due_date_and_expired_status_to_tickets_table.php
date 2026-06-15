<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dateTime('due_date')->nullable()->after('schedule_end');
        });

        DB::statement("ALTER TABLE tickets ALTER COLUMN status TYPE VARCHAR(255)");
        DB::statement("ALTER TABLE tickets ALTER COLUMN status SET DEFAULT 'pending'");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status::text IN ('pending','queued','approved_admin','assigned','in_progress','completed','rejected','cancelled','expired'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tickets DROP CONSTRAINT tickets_status_check");
        DB::statement("ALTER TABLE tickets ALTER COLUMN status TYPE text USING status::text");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status::text IN ('pending','queued','approved_admin','assigned','in_progress','completed','rejected','cancelled'))");
        
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('due_date');
        });
    }
};