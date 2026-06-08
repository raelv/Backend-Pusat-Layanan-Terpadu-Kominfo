<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

        protected $fillable = [
        'name',
        'email',
        'nip', 
        'password', 
        'role', 
        'attendance_status', 
        'bidang', 
        'telegram_id'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    // Relasi: Tiket yang diajukan (sebagai OPD)
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    // Relasi: Tiket yang dikerjakan (sebagai Staf)
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_staff_id');
    }

    // Helper: Hitung tugas aktif
    public function getActiveTaskCountAttribute(): int
    {
        return $this->assignedTasks()
            ->whereIn('status', ['assigned', 'in_progress', 'approved_admin'])
            ->count();
    }

    // Scope: Cari staf yang Sedia (Masuk & Tidak punya tugas)
    // PERBAIKAN BUG: 'staf' diubah jadi 'staff' (tanpa a)
    public function scopeAvailableStaff($query)
    {
        return $query->where('role', 'staff')
            ->where('attendance_status', 'Masuk')
            ->whereDoesntHave('assignedTasks', function ($q) {
                $q->whereIn('status', ['assigned', 'in_progress', 'approved_admin']);
            });
    }
}