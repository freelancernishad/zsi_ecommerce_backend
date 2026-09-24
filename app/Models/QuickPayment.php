<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuickPayment extends Model
{
    use HasFactory;

    protected $table = 'quick_payments';

    protected $fillable = [
        'name',
        'email',
        'reason',
        'amount',
        'currency',
        'status',
        'payment_method',
        'transaction_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];
}
