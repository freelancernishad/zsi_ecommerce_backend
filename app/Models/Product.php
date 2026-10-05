<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $casts = [
        'tags' => 'json',
        'variant_options' => 'json',
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
        'variant_options',
        'size_guide',
        'shipping_policy',
        'return_policy',
        'cj_product_id',
        'cj_spu',
        'is_cj_product',
        'cj_delivery_cycle',
        'cj_synced_at',
        'amazon_asin',
        'amazon_url',
        'is_amazon_product',
        'amazon_synced_at',
        'import_source',
        'aliexpress_product_id',
        'aliexpress_url',
        'is_aliexpress_product',
        'aliexpress_synced_at',
        'aliexpress_store_name',
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

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_product');
    }

    protected static function booted()
    {
        static::deleted(function ($product) {
            $product->variants()->delete();
        });
        static::restored(function ($product) {
            $product->variants()->restore();
        });
    }
}
