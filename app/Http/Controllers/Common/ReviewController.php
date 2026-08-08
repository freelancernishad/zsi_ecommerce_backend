<?php

namespace App\Http\Controllers\Common;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\FileSystem\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ReviewController extends Controller
{
    protected $fileUploadService;

    public function __construct(FileUploadService $fileUploadService)
    {
        $this->fileUploadService = $fileUploadService;
    }

    public function index()
    {
        $reviews = Review::where('user_id', Auth::id())
            ->with(['product.images'])
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $reviews
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $imageUrls = [];
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $imageUrls[] = $this->fileUploadService->uploadFileToS3($image, 'reviews');
                }
            }

            $review = Review::create([
                'user_id' => Auth::id(),
                'product_id' => $request->product_id,
                'rating' => $request->rating,
                'comment' => $request->comment,
                'images' => $imageUrls,
                'status' => 'active', // Automatically active for now, can add moderation later
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Review submitted successfully',
                'data' => $review->load('user')
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to submit review: ' . $e->getMessage()
            ], 500);
        }
    }

    public function pendingReviews()
    {
        $userId = Auth::id();
        
        // Find which products have already been reviewed by this user
        $reviewedProductIds = Review::where('user_id', $userId)
            ->pluck('product_id')
            ->toArray();

        // Find all OrderItems in DELIVERED orders for this user that haven't been reviewed
        $pendingOrderItems = \App\Models\OrderItem::whereHas('order', function ($query) use ($userId) {
            $query->where('user_id', $userId)
                  ->where('status', 'delivered');
        })
        ->whereNotIn('product_id', $reviewedProductIds)
        ->with(['product.images', 'variant'])
        ->get();

        return response()->json([
            'status' => 'success',
            'data' => $pendingOrderItems
        ]);
    }
}
