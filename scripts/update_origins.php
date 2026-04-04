<?php
use App\Models\AllowedOrigin;

AllowedOrigin::where('origin_url', 'postman')->delete();
AllowedOrigin::create(['origin_url' => 'http://localhost:3000']);
AllowedOrigin::create(['origin_url' => 'http://127.0.0.1:3000']);
AllowedOrigin::create(['origin_url' => '*']); // Temporarily allow all for testing
echo "Origins updated successfully\n";
