<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImageSignature extends Model
{
    protected $table = 'product_image_signatures';

    protected $fillable = [
        'product_id',
        'image_url',
        'dhash',
        'color_hist',
        'region_hashes',
        'indexed_at',
    ];

    protected $casts = [
        'color_hist' => 'array',
        'region_hashes' => 'array',
        'indexed_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
