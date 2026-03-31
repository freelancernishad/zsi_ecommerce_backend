<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $casts = [
        'tags' => 'json',
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'short_description',
        'price',
        'sale_price',
        'original_price',
        'cost_per_item',
        'stock_quantity',
        'sku',
        'barcode',
        'category_id',
        'sub_category_id',
        'sub_sub_category_id',
        'brand_id',
        'status',
        'weight',
        'weight_unit',
        'height',
        'height_unit',
        'width',
        'width_unit',
        'seo_title',
        'seo_description',
        'url_handle',
        'continue_selling',
        'is_physical',
        'charge_tax',
        'rating',
        'review_count',
        'tags',
    ];

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function orderItems()
    {
        return $this->hasMany(\App\Models\OrderItem::class);
    }
}
