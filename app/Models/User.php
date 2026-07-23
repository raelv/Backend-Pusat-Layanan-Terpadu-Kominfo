<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'telegram_id', 
        'telegram_chat_id', 
        'service_access'
    ];

    protected $hidden = ['password', 'remember_token'];
    
    protected $casts = [
        'email_verified_at' => 'datetime',
        'service_access' => 'array',
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

    // Relasi: Multi-Bidang (Many-to-Many)
    public function bidangs(): BelongsToMany
    {
        return $this->belongsToMany(Bidang::class, 'user_bidang', 'user_id', 'bidang_id');
    }

    // Helper: Hitung tugas aktif (Multi-tasking)
    public function getActiveTaskCountAttribute(): int
    {
        return $this->assignedTasks()
            ->whereIn('status', ['assigned', 'in_progress', 'approved_admin'])
            ->count();
    }

    // Helper: Cek Overload (Jika >= 2 tugas)
    public function getIsOverloadedAttribute()
    {
        return $this->active_task_count >= 2; // Minimal ngurus 2 tugas baru dianggap Overload
    }

    // Helper: Cek hak akses layanan (Checklist)
    public function hasServiceAccess($category)
    {
        return in_array(strtolower($category), $this->service_access ?? []);
    }
}