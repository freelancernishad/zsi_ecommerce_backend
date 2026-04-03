<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'stripe_payment_method_id',
        'type',
        'brand',
        'last4',
        'expiry_date',
        'cardholder_name',
        'is_default',
        'status',
        'brand_icon'
    ];

    protected $appends = ['brand_icon_url'];

    public function getBrandIconUrlAttribute()
    {
        return $this->brand_icon ? asset($this->brand_icon) : null;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
