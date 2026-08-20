<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id', 
        'combination_label', 
        'price', 
        'discount_price', 
        'sku', 
        'available_quantity',
        'stock',
        'color', 
        'size', 
        'price_adjustment',
        'cj_vid',
        'cj_variant_sku',
        'cj_weight'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'variant_id');
    }
}
