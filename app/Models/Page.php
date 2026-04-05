<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'content',
        'banner_image',
        'meta_title',
        'meta_description',
        'status',
        'metadata',
        'tags',
    ];

    protected $casts = [
        'metadata' => 'array',
        'tags' => 'json',
    ];
}
