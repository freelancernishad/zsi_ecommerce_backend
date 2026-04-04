<?php

use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$slug = 'glowfresh-aloe-vera-face-wash-1774798427';
$product = Product::where('slug', 'like', "%$slug%")->first();

if (!$product) {
    die("Product not found.\n");
}

echo "Found product: " . $product->name . " (ID: " . $product->id . ")\n";

// Ensure we have at least 5 users
$users = User::limit(5)->get();
if ($users->count() < 5) {
    echo "Creating dummy users...\n";
    for ($i = $users->count(); $i < 5; $i++) {
        User::create([
            'name' => "User " . ($i + 1),
            'email' => "user" . ($i + 1) . "@example.com",
            'password' => bcrypt('password'),
        ]);
    }
    $users = User::limit(5)->get();
}

$reviews = [
    [
        'rating' => 5,
        'comment' => "This face wash is amazing! My skin feels so fresh and hydrated after using it. Highly recommend for sensitive skin.",
    ],
    [
        'rating' => 4,
        'comment' => "Really good product. It has a natural scent and doesn't dry out my skin. Will buy again.",
    ],
    [
        'rating' => 5,
        'comment' => "The aloe vera effect is very soothing. I've been using it for a week and I can see a visible glow on my face.",
    ],
    [
        'rating' => 4,
        'comment' => "Decent product for the price. Packaging is also good.",
    ],
    [
        'rating' => 5,
        'comment' => "Best aloe vera face wash I've ever used. Worth every penny!",
    ]
];

echo "Adding reviews...\n";
foreach ($reviews as $index => $reviewData) {
    $user = $users[$index % $users->count()];
    
    Review::updateOrCreate(
        [
            'product_id' => $product->id,
            'user_id' => $user->id,
            'comment' => $reviewData['comment']
        ],
        [
            'rating' => $reviewData['rating']
        ]
    );
}

echo "Successfully added " . count($reviews) . " reviews to " . $product->name . ".\n";
