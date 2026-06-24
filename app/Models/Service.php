<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $table = 'services'; 
    
    protected $fillable = [
        'name', 
        'slug', 
        'description', 
        'form_schema', 
        'requires_admin_approval',
        'is_active',
        'sla_days',
        'category'
    ];
    
    protected $casts = [
        'form_schema' => 'array',
    ];

    public function getIsScheduleBasedAttribute(): bool
    {
        return in_array($this->category, ['zoom', 'command_center']);
    }

    public function getIsSlaBasedAttribute(): bool
    {
        return !$this->is_schedule_based;
    }

    public function getCategoryLabelAttribute(): string
    {
        return match($this->category) {
            'zoom' => 'Zoom',
            'command_center' => 'Command Center',
            default => 'IT',
        };
    }
}