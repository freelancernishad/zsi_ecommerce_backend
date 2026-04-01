<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'product_id', 'rating', 'comment', 'images', 'status'];
    
    protected static function booted()
    {
        static::saved(function ($review) {
            $review->syncProductRating();
        });

        static::deleted(function ($review) {
            $review->syncProductRating();
        });
    }

    public function syncProductRating()
    {
        if ($this->product) {
            $this->product->update([
                'rating' => $this->product->reviews()->avg('rating') ?: 0,
                'review_count' => $this->product->reviews()->count()
            ]);
        }
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
