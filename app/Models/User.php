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

    public function tickets(): HasMany 
    { 
        return $this->hasMany(Ticket::class); 
    }

    public function assignedTasks(): HasMany 
    { 
        return $this->hasMany(Ticket::class, 'assigned_staff_id'); 
    }

    public function bidangs(): BelongsToMany
    {
        return $this->belongsToMany(Bidang::class, 'user_bidang', 'user_id', 'bidang_id');
    }

    public function getActiveTaskCountAttribute(): int
    {
        return $this->assignedTasks()
            ->whereIn('status', ['assigned', 'in_progress', 'approved_admin'])
            ->count();
    }

    public function getIsOverloadedAttribute()
    {
        return $this->active_task_count >= 2; 
    }

    public function hasServiceAccess($category)
    {
        return in_array(strtolower($category), $this->service_access ?? []);
    }

    public function getBidangAttribute($value)
    {
        if ($value === '[]' || $value === '' || $value === null) {
            return null; 
        }
        return $value;
    }
}