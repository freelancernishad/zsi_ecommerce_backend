<?php

namespace App\Http\Controllers\Common;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function index()
    {
        $brands = Brand::where('status', 'active')->get();

        return response()->json([
            'status' => 'success',
            'data' => $brands
        ]);
    }
}
