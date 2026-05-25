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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('service_id')->constrained();
            $table->foreignId('assigned_staff_id')->nullable()->constrained('users');
            $table->jsonb('form_data'); 
            $table->dateTime('schedule_start')->nullable();
            $table->dateTime('schedule_end')->nullable();
            $table->enum('status', [
                'pending', 'queued', 'approved_admin', 'assigned', 
                'in_progress', 'completed', 'rejected', 'cancelled'
            ])->default('pending');
            $table->boolean('is_skm_filled')->default(false);
            $table->timestamps();
            $table->index(['schedule_start', 'schedule_end', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
