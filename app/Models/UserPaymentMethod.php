<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'last4',
        'expiry_date',
        'cardholder_name',
        'is_default'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
