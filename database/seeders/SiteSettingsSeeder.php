<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SiteSetting;

class SiteSettingsSeeder extends Seeder
{
    public function run()
    {
        $settings = [
            [
                'key' => 'home_hero',
                'value' => [
                    'title' => 'MEGA SALE',
                    'subtitle' => 'Exclusive Offers On Baby Clothing',
                    'discount' => '50%',
                    'image' => 'https://via.placeholder.com/800x600/ffffff/000000?text=Kids+Apparel+Models'
                ],
                'type' => 'json'
            ],
            [
                'key' => 'home_spring_fling',
                'value' => [
                    'title' => 'Spring Fling Sale',
                    'subtitle' => 'Refresh Your Wardrobe with Fresh Styles!',
                    'button_text' => 'Shop Now',
                    'image' => 'https://via.placeholder.com/600x600/ffffff/000000?text=Spring+Model'
                ],
                'type' => 'json'
            ],
            [
                'key' => 'home_trends_title',
                'value' => 'Top Trends: Must-Have Categories & Global Favorites',
                'type' => 'text'
            ],
            [
                'key' => 'home_viral_pick_id',
                'value' => '1', // Assuming product ID 1 exists from previous seeding
                'type' => 'text'
            ],
            [
                'key' => 'home_brands',
                'value' => [
                    ['id' => 1, 'name' => 'Orange', 'image' => 'https://via.placeholder.com/150/ff7a59/ffffff?text=Orange'],
                    ['id' => 2, 'name' => 'Aeternum', 'image' => 'https://via.placeholder.com/150/000000/ffffff?text=Aeternum'],
                    ['id' => 3, 'name' => 'Biogas', 'image' => 'https://via.placeholder.com/150/fbbf24/ffffff?text=Biogas'],
                    ['id' => 4, 'name' => 'Aurora Vogue', 'image' => 'https://via.placeholder.com/150/db2777/ffffff?text=Aurora'],
                    ['id' => 5, 'name' => 'Jeans & Co', 'image' => 'https://via.placeholder.com/150/eab308/ffffff?text=Jeans'],
                    ['id' => 6, 'name' => 'Floralia', 'image' => 'https://via.placeholder.com/150/fbcfe8/ffffff?text=Floralia'],
                    ['id' => 7, 'name' => 'Elaria', 'image' => 'https://via.placeholder.com/150/f59e0b/ffffff?text=Elaria'],
                    ['id' => 8, 'name' => 'Greenscape', 'image' => 'https://via.placeholder.com/150/22c55e/ffffff?text=Greens'],
                ],
                'type' => 'json',
                'group' => 'home'
            ],
            // Live Chat Configuration (Crisp & Tawk.to)
            ['key' => 'chat_enabled', 'value' => '1', 'type' => 'text', 'group' => 'chat'],
            ['key' => 'chat_provider', 'value' => 'crisp', 'type' => 'text', 'group' => 'chat'],
            ['key' => 'crisp_website_id', 'value' => '88d6f398-3277-4f3e-8fd0-d2acf3b7b412', 'type' => 'text', 'group' => 'chat'],
            ['key' => 'tawk_property_id', 'value' => '', 'type' => 'text', 'group' => 'chat'],
            ['key' => 'tawk_widget_id', 'value' => 'default', 'type' => 'text', 'group' => 'chat'],
            ['key' => 'tawk_direct_chat_link', 'value' => '', 'type' => 'text', 'group' => 'chat'],
            ['key' => 'chat_visibility', 'value' => 'all', 'type' => 'text', 'group' => 'chat'],
            ['key' => 'chat_sync_user', 'value' => '1', 'type' => 'text', 'group' => 'chat'],
            ['key' => 'chat_mobile_enabled', 'value' => '1', 'type' => 'text', 'group' => 'chat']
        ];

        foreach ($settings as $setting) {
            SiteSetting::updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'type' => $setting['type'],
                    'group' => $setting['group'] ?? 'general'
                ]
            );
        }
    }
}
