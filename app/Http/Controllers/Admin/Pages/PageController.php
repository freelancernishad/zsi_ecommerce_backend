<?php

namespace App\Http\Controllers\Admin\Pages;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\FileSystem\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PageController extends Controller
{
    protected $fileUploadService;

    public function __construct(FileUploadService $fileUploadService)
    {
        $this->fileUploadService = $fileUploadService;
    }

    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $search = $request->get('search');
        $status = $request->get('status');

        $query = Page::query();

        if ($search) {
            $query->where('title', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
        }

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $pages = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $pages,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:pages,slug',
            'content' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'status' => 'required|in:draft,published',
            'banner_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'metadata' => 'nullable|string',
            'tags' => 'nullable|string',
        ]);

        $slug = $request->slug ?: Str::slug($request->title);
        
        // Ensure slug is unique if auto-generated
        if (!$request->slug) {
            $originalSlug = $slug;
            $count = 1;
            while (Page::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $count++;
            }
        }

        $bannerPath = null;
        if ($request->hasFile('banner_image')) {
            $bannerPath = $this->fileUploadService->uploadFileToS3($request->file('banner_image'), 'pages/banners');
        }

        $page = Page::create([
            'title' => $request->title,
            'slug' => $slug,
            'content' => $request->content,
            'banner_image' => $bannerPath,
            'meta_title' => $request->meta_title,
            'meta_description' => $request->meta_description,
            'status' => $request->status,
            'metadata' => $request->has('metadata') ? json_decode($request->metadata, true) : null,
            'tags' => $request->has('tags') ? json_decode($request->tags, true) : [],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $page,
        ]);
    }

    public function show($id)
    {
        $page = Page::findOrFail($id);
        return response()->json([
            'status' => 'success',
            'data' => $page,
        ]);
    }

    public function update(Request $request, $id)
    {
        $page = Page::findOrFail($id);

        $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:pages,slug,' . $id,
            'content' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'status' => 'required|in:draft,published',
            'banner_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'metadata' => 'nullable|string',
            'tags' => 'nullable|string',
        ]);

        $data = [
            'title' => $request->title,
            'slug' => $request->slug,
            'content' => $request->content,
            'meta_title' => $request->meta_title,
            'meta_description' => $request->meta_description,
            'status' => $request->status,
            'metadata' => $request->has('metadata') ? json_decode($request->metadata, true) : $page->metadata,
            'tags' => $request->has('tags') ? json_decode($request->tags, true) : $page->tags,
        ];

        if ($request->hasFile('banner_image')) {
            $data['banner_image'] = $this->fileUploadService->uploadFileToS3($request->file('banner_image'), 'pages/banners');
        }

        $page->update($data);

        return response()->json([
            'status' => 'success',
            'data' => $page,
        ]);
    }

    public function destroy($id)
    {
        $page = Page::findOrFail($id);
        $page->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Page deleted successfully',
        ]);
    }

    public function getBySlug($slug)
    {
        $page = Page::where('slug', $slug)->where('status', 'published')->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => $page,
        ]);
    }
}
