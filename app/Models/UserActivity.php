<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserActivity extends Model
{
    protected $fillable = [
        'user_id',
        'session_id',
        'activity_type',
        'entity_id',
        'entity_type',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array'
    ];
    
    // Disable updated_at since it's not in the migration
    const UPDATED_AT = null;

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
