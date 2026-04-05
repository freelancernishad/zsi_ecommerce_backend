<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiAIService
{
    protected $apiKey;
    protected $baseUrl = 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash-lite:generateContent';

    public function __construct()
    {
        // 1. Try SiteSetting model (used by the new Next.js AI settings page)
        $siteKey = SiteSetting::getValue('gemini_api_key');
        
        // 2. Try SystemSetting model (used by the Blade-based core settings)
        $systemKey = SystemSetting::getValue('gemini_api_key');
        
        // 3. Fallback to .env
        $this->apiKey = $siteKey ?: ($systemKey ?: config('services.gemini.key'));

        if (!$this->apiKey) {
            Log::warning('GeminiAIService: No API key found in SiteSetting, SystemSetting, or .env');
        }
    }

    /**
     * Generate content based on a prompt.
     *
     * @param string $prompt
     * @return string|null
     */
    public function generateContent(string $prompt)
    {
        if (!$this->apiKey) {
            Log::error('Gemini API Error: API key is not set.');
            return null;
        }

        try {
            $apiUrl = $this->baseUrl . '?key=' . $this->apiKey;
            
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($apiUrl, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 1.0,
                    'topP' => 0.95,
                    'topK' => 40,
                    'maxOutputTokens' => 8192,
                ]
            ]);

            if ($response->successful()) {
                $result = $response->json();
                if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                    $text = $result['candidates'][0]['content']['parts'][0]['text'];
                    return $this->cleanHtml($text);
                }
                
                Log::error('Gemini API Error: Unexpected response structure.', ['response' => $result]);
                return null;
            }

            // Enhanced error logging for 400/403/500 errors from Google
            $errorBody = $response->json();
            Log::error('Gemini API Request Failed', [
                'status' => $response->status(),
                'error' => $errorBody['error'] ?? 'Unknown API Error',
                'prompt_preview' => substr($prompt, 0, 100) . '...'
            ]);
            
            return null;

        } catch (\Exception $e) {
            Log::error('Gemini AIService Exception: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Clean the AI response by removing markdown blocks and extracting body content.
     */
    private function cleanHtml(string $text)
    {
        // 1. Remove Markdown code blocks if present
        $text = preg_replace('/```(?:html)?\s*(.*?)\s*```/is', '$1', $text);
        
        // 2. If it's a full HTML document, extract only what's inside <body>
        if (stripos($text, '<body') !== false) {
            preg_match('/<body[^>]*>(.*?)<\/body>/is', $text, $matches);
            if (isset($matches[1])) {
                $text = $matches[1];
            }
        }

        return trim($text);
    }
}
