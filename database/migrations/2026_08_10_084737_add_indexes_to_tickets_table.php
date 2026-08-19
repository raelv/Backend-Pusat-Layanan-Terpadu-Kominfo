<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index('status', 'idx_tickets_status');
            $table->index('user_id', 'idx_tickets_user_id');
            $table->index('assigned_staff_id', 'idx_tickets_staff_id');
            $table->index('service_id', 'idx_tickets_service_id');
            $table->index('created_at', 'idx_tickets_created_at');
            $table->index(['status', 'created_at'], 'idx_tickets_status_created');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['service_id']);
            $table->dropIndex(['assigned_staff_id']);
            $table->dropIndex(['user_id']);
            $table->dropIndex(['status']);
        });
    }
};