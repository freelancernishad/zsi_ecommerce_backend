<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    /** @use HasFactory<\Database\Factories\BrandFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'logo',
        'description',
        'status',
        'show_on_home',
        'home_order',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
