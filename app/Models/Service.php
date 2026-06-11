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
        'requires_admin_approval', // TYPO SUDAH DIPERBAIKI
        'is_active',
        'sla_days' // TAMBAHAN BARU
    ];
    
    protected $casts = [
        'form_schema' => 'array',
    ];
}