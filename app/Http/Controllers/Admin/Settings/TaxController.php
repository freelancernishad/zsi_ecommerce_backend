<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\Tax;
use Illuminate\Http\Request;

class TaxController extends Controller
{
    public function index()
    {
        return response()->json(['status' => 'success', 'data' => Tax::all()]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'country' => 'required|string',
            'state' => 'required|string',
            'tax_rate' => 'required|numeric|min:0|max:100',
        ]);

        $tax = Tax::updateOrCreate(
            ['country' => $request->country, 'state' => $request->state],
            ['tax_rate' => $request->tax_rate]
        );

        return response()->json(['status' => 'success', 'data' => $tax]);
    }

    public function show($id)
    {
        return response()->json(['status' => 'success', 'data' => Tax::findOrFail($id)]);
    }

    public function destroy($id)
    {
        Tax::findOrFail($id)->delete();
        return response()->json(['status' => 'success', 'message' => 'Tax rate deleted successfully']);
    }
}
