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


        // ebsdeals.com
        'ebsdeals.com'    => env('DB_DATABASE_EBSDEALS', 'mustgyyu_ebsdeals'),
        'www.ebsdeals.com'=> env('DB_DATABASE_EBSDEALS', 'mustgyyu_ebsdeals'),
        'admin.ebsdeals.com'=> env('DB_DATABASE_EBSDEALS', 'mustgyyu_ebsdeals'),



        // cheaperbro.com
        'cheaperbro.com'    => env('DB_DATABASE_EBSDEALS', 'mustgyyu_cheaperbro'),
        'www.cheaperbro.com'=> env('DB_DATABASE_EBSDEALS', 'mustgyyu_cheaperbro'),
        'admin.cheaperbro.com'=> env('DB_DATABASE_EBSDEALS', 'mustgyyu_cheaperbro'),


        
        // vercel and local
        'goldenmarkstorecom.vercel.app'    => env('DB_DATABASE_EBSDEALS', 'mustgyyu_cheaperbro'),
        'www.goldenmarkstorecom.vercel.app' => env('DB_DATABASE_EBSDEALS', 'mustgyyu_cheaperbro'),
        'ecommerce-admin-zsi.vercel.app' => env('DB_DATABASE_EBSDEALS', 'mustgyyu_cheaperbro'),
        'localhost:3001' => env('DB_DATABASE_EBSDEALS', 'mustgyyu_cheaperbro'),





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
