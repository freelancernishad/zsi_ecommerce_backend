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
            'title' => 'required|string|max:255',
            'prompt' => 'nullable|string',
        ]);

        $title = $request->input('title');
        
        // Construct a prime prompt for professional ecommerce content
        $aiPrompt = "Write high-quality HTML content for a professional ecommerce store page titled '{$title}'. 
        The content should include:
        - Professional headers (h2, h3)
        - Well-structured paragraphs
        - Bullet points or lists where appropriate
        - A professional, customer-focused tone
        - Placeholder brand name: [ShopName]
        
        Only return the HTML content, no extra talk.";

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
