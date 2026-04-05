<?php

namespace App\Http\Controllers\Admin\AI;

use App\Http\Controllers\Controller;
use App\Services\GeminiAIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AIController extends Controller
{
    protected $geminiService;

    public function __construct(GeminiAIService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    /**
     * Generate content for a store page using AI.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateContent(Request $request)
    {
        $request->validate([
            'title' => 'nullable|string|max:255',
            'prompt' => 'nullable|string|max:1000',
        ]);

        // --- MAXIMUM REALISM GATHERING (Prices, Contacts, Socials) ---
        // 1. Core Identity & Contacts
        $settings = \App\Models\SiteSetting::whereIn('key', ['site_name', 'site_description', 'site_email', 'site_phone', 'site_address', 'facebook_url', 'instagram_url', 'site_jurisdiction'])->get()->pluck('value', 'key');

        $storeName = $settings['site_name'] ?? \App\Models\SystemSetting::getValue('site_name') ?? 'FlipShop';
        
        // Clean storeName if it is an email (common with fresh admin setups like admin@admin.com)
        if (filter_var($storeName, FILTER_VALIDATE_EMAIL)) {
            $storeName = ucwords(explode('@', $storeName)[0]);
        }

        $storeDescription = $settings['site_description'] ?? \App\Models\SystemSetting::getValue('site_description') ?? 'Premium ecommerce destination.';
        $sitePhone = $settings['site_phone'] ?? '+880 1XXX-XXXXXX';
        $siteEmail = $settings['site_email'] ?? 'support@' . strtolower(str_replace(' ', '', $storeName)) . '.com';
        $siteAddress = $settings['site_address'] ?? 'Dhaka, Bangladesh';
        
        $contactInfo = "Email: {$siteEmail} | Phone: {$sitePhone} | Address: {$siteAddress}";
        $socials = "FB: " . ($settings['facebook_url'] ?? '#') . " | IG: " . ($settings['instagram_url'] ?? '#');
        $jurisdiction = $settings['site_jurisdiction'] ?? 'Dhaka, Bangladesh';

        // 2. Marketing Soul
        $heroData = \App\Models\SiteSetting::getValue('home_hero');
        $marketingFocus = is_array($heroData) ? "CURRENT CAMPAIGN: {$heroData['title']} - {$heroData['subtitle']} ({$heroData['discount']} OFF)" : "";

        // 3. Brands & Partners
        $brands = \App\Models\SiteSetting::getValue('home_brands');
        $brandList = is_array($brands) ? implode(', ', array_column($brands, 'name')) : 'Exclusive Premium Brands';

        // 4. RICH Product & Category Details (WITH PRICES)
        $categories = \App\Models\Category::where('status', 'published')
            ->limit(6)
            ->get(['name', 'description'])
            ->map(fn($c) => "- CATEGORY: {$c->name}. Focus: " . strip_tags(\Illuminate\Support\Str::limit($c->description, 120)))
            ->implode("\n");

        $recentProducts = \App\Models\Product::where('status', 'published')
            ->latest()
            ->limit(10)
            ->get(['name', 'short_description', 'price', 'sale_price'])
            ->map(function($p) {
                $priceText = $p->sale_price ? "Price: {$p->sale_price} (Discounts applied from {$p->price})" : "Price: {$p->price}";
                return "- PRODUCT: {$p->name} | {$priceText} | Features: " . strip_tags(\Illuminate\Support\Str::limit($p->short_description, 100));
            })
            ->implode("\n");

        // 5. Digital Footprint
        $existingPages = \App\Models\Page::where('status', 'published')->limit(5)->pluck('title')->toArray();

        // 6. SYSTEM INTELLIGENCE
        $systemManifest = [
            "PAYMENTS: Stripe & SSLCommerz Secured.",
            "DISCOUNTS: Advanced Coupon engine active.",
            "SHIPPING: Worldwide delivery with real-time tracking.",
            "ECOSYSTEM: Multi-tier categories and Brand partners.",
        ];
        $logicManifest = implode(" | ", $systemManifest);

        // --- CONSTRUCT THE ULTIMATE KNOWLEDGE BASE ---
        $contextLines = [
            "STORE: {$storeName}",
            "ABOUT: {$storeDescription}",
            "CONTACT: {$contactInfo}",
            "SOCIALS: {$socials}",
            "\nCATEGORIES:\n{$categories}",
            "\nPRODUCTS & PRICING:\n{$recentProducts}",
            "\nPARTNERS: {$brandList}",
            $marketingFocus,
            "PAGES ALREADY ONLINE: " . implode(', ', $existingPages),
            "\nTECH STACK:\n{$logicManifest}",
        ];
        
        $websiteContext = implode("\n", array_filter($contextLines));

        // LOGGING (Final proof of reading)
        \Illuminate\Support\Facades\Log::info("AI FINAL ULTIMATE CONTEXT LOADED", ['size' => strlen($websiteContext)]);

        $customPrompt = $request->input('prompt');
        $title = $request->input('title');

        $sitePhone = $settings['site_phone'] ?? 'Contact us via website';
        $siteEmail = $settings['site_email'] ?? 'support@' . strtolower($storeName) . '.com';

        $aiPrompt = "You are a Senior Copywriter and Product Manager working at '{$storeName}'. You know this website inside and out.
        
        YOUR COMPLETE KNOWLEDGE BASE:
        {$websiteContext}

        TASK:
        Generate expert, conversion-optimized HTML content for: '{$title}'.
        " . ($customPrompt ? "USER INSTRUCTION: {$customPrompt}" : "") . "
        
        INSTRUCTIONS FOR 100% REALISM:
        - NEVER use placeholders. If you need a phone number, use our real one: '{$sitePhone}'.
        - If you need a contact email, use: '{$siteEmail}'.
        - If you need a price, pick one from our PRODUCTS & PRICING section or invent a similar one based on our niche.
        - Reference our specific products and categories as if you are a real employee who loves these items.
        - Mention our secure Stripe checkout and technical features to build trust.
        
        CRITICAL RULES:
        1. Brand: Always '{$storeName}'.
        2. Format: Return ONLY semantic HTML (h2, h3, p, ul, li). No head/body/html tags.
        3. NO BRACKETS: No [ShopName], [Bracket], or [Placeholder]. Use ONLY real names and data.
        4. Absolute Excellence: Write like a $1,000/hour copywriting agency.";

        $content = $this->geminiService->generateContent($aiPrompt);

        if ($content) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'content' => $content
                ]
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Failed to generate content with AI. Please check your API key.'
        ], 500);
    }
}
