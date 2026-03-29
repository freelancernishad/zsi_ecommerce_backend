<?php

namespace App\Http\Controllers\User\AddressManagement;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AddressController extends Controller
{
    public function index()
    {
        $addresses = Address::where('user_id', Auth::id())->get();
        return response()->json(['status' => 'success', 'data' => $addresses]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'email' => 'required|email',
            'phone' => 'required|string',
            'street_address' => 'required|string',
            'city' => 'required|string',
            'zip_code' => 'required|string',
            'country' => 'required|string',
        ]);

        if ($request->is_default) {
            Address::where('user_id', Auth::id())->update(['is_default' => false]);
        }

        $address = Address::create(array_merge($request->all(), [
            'user_id' => Auth::id()
        ]));

        return response()->json(['status' => 'success', 'data' => $address]);
    }

    public function update(Request $request, $id)
    {
        $address = Address::where('user_id', Auth::id())->findOrFail($id);
        
        if ($request->is_default) {
            Address::where('user_id', Auth::id())->update(['is_default' => false]);
        }

        $address->update($request->all());

        return response()->json(['status' => 'success', 'data' => $address]);
    }

    public function destroy($id)
    {
        Address::where('user_id', Auth::id())->findOrFail($id)->delete();
        return response()->json(['status' => 'success', 'message' => 'Address deleted']);
    }
}
