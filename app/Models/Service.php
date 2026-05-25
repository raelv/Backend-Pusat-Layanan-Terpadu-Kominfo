<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $table = 'services'; // Wajib ada ini
    protected $fillable = ['name', 'slug', 'description', 'form_schema', 'requireds_admin_approval', 'is_active'];
    
    protected $casts = [
        'form_schema' => 'array',
    ];
}