<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'first_name', 'last_name', 'email', 'phone', 
        'country', 'street_address', 'city', 'state', 'zip_code', 
        'type', 'is_default'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
