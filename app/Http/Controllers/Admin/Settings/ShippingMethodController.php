<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\ShippingMethod;
use Illuminate\Http\Request;

class ShippingMethodController extends Controller
{
    public function index()
    {
        return response()->json(['status' => 'success', 'data' => ShippingMethod::all()]);
    }

    public function store(Request $request)
    {
        $id = $request->id;
        $request->validate([
            'name' => 'required|string|unique:shipping_methods,name,' . $id,
            'cost' => 'required|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        $method = ShippingMethod::updateOrCreate(
            ['id' => $id],
            $request->only(['name', 'cost', 'is_active'])
        );

        return response()->json(['status' => 'success', 'data' => $method]);
    }

    public function show($id)
    {
        return response()->json(['status' => 'success', 'data' => ShippingMethod::findOrFail($id)]);
    }

    public function destroy($id)
    {
        ShippingMethod::findOrFail($id)->delete();
        return response()->json([
            'status' => 'success', 
            'message' => 'Shipping method deleted successfully'
        ]);
    }
}
