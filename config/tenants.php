<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tenant Domains & Database Mapping
    |--------------------------------------------------------------------------
    |
    | Maps incoming domain hostnames (or X-Tenant-Domain headers) to target
    | database names configured in environment variables or default names.
    |
    */

    'domains' => [
        // GoldenMark Store
        'goldenmarkstore.com'        => env('DB_DATABASE_GOLDENMARK', 'mustgyyu_zsi_ecommerce_db'),
        'www.goldenmarkstore.com'    => env('DB_DATABASE_GOLDENMARK', 'mustgyyu_zsi_ecommerce_db'),
        'admin.goldenmarkstore.com'    => env('DB_DATABASE_GOLDENMARK', 'mustgyyu_zsi_ecommerce_db'),

        // Bismillah Fashion USA
        'bismillahfashionusa.com'    => env('DB_DATABASE_BISMILLAH', 'mustgyyu_bismillahfashionusa_db'),
        'www.bismillahfashionusa.com'=> env('DB_DATABASE_BISMILLAH', 'mustgyyu_bismillahfashionusa_db'),
        'admin.bismillahfashionusa.com'=> env('DB_DATABASE_BISMILLAH', 'mustgyyu_bismillahfashionusa_db'),


        
        // Bismillah Fashion USA
        'goldenmarkstorecom.vercel.app'    => env('DB_DATABASE_BISMILLAH', 'mustgyyu_bismillahfashionusa_db'),
        'www.goldenmarkstorecom.vercel.app' => env('DB_DATABASE_BISMILLAH', 'mustgyyu_bismillahfashionusa_db'),
        'ecommerce-admin-zsi.vercel.app' => env('DB_DATABASE_BISMILLAH', 'mustgyyu_bismillahfashionusa_db'),


        // Local development hostnames
        'goldenmark.test'            => env('DB_DATABASE_GOLDENMARK', 'goldenmarkstore_db'),
        'bismillah.test'             => env('DB_DATABASE_BISMILLAH', 'bismillahfashionusa_db'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Fallback Database
    |--------------------------------------------------------------------------
    */
    'fallback' => env('DB_DATABASE', 'zsi_ecommerce'),
];
