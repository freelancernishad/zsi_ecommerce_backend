<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLog extends Model
{
    protected $fillable = [
        'user_id',
        'causer_id',
        'causer_name',
        'category',
        'action',
        'description',
        'metadata',
        'device',
        'platform',
        'browser',
        'is_success',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
