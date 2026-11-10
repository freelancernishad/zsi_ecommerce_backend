<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventorySyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_name',
        'supplier',
        'supplier_id',
        'status',
        'changes_detected',
        'old_stock',
        'new_stock',
        'old_price',
        'new_price',
        'details',
        'error_message',
    ];

    protected $casts = [
        'changes_detected' => 'boolean',
        'details' => 'json',
        'old_stock' => 'integer',
        'new_stock' => 'integer',
        'old_price' => 'float',
        'new_price' => 'float',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

