<?php

namespace App\Http\Controllers\User\Management;

use App\Http\Controllers\Controller;
use App\Models\UserPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserPaymentMethodController extends Controller
{
    public function index()
    {
        $methods = UserPaymentMethod::where('user_id', Auth::id())->get();
        return response()->json(['status' => 'success', 'data' => $methods]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required|string',
            'last4' => 'required|string|size:4',
            'expiry_date' => 'required|string',
            'cardholder_name' => 'required|string',
            'is_default' => 'boolean'
        ]);

        if ($request->is_default) {
            UserPaymentMethod::where('user_id', Auth::id())->update(['is_default' => false]);
        }

        $method = UserPaymentMethod::create([
            'user_id' => Auth::id(),
            'type' => $request->type,
            'last4' => $request->last4,
            'expiry_date' => $request->expiry_date,
            'cardholder_name' => $request->cardholder_name,
            'is_default' => $request->is_default ?? false,
        ]);

        return response()->json(['status' => 'success', 'data' => $method]);
    }

    public function update(Request $request, $id)
    {
        $method = UserPaymentMethod::where('user_id', Auth::id())->findOrFail($id);

        if ($request->has('is_default') && $request->is_default) {
            UserPaymentMethod::where('user_id', Auth::id())->update(['is_default' => false]);
            $method->is_default = true;
        }

        $method->fill($request->only(['expiry_date', 'cardholder_name']));
        $method->save();

        return response()->json(['status' => 'success', 'data' => $method]);
    }

    public function destroy($id)
    {
        $method = UserPaymentMethod::where('user_id', Auth::id())->findOrFail($id);
        $method->delete();

        return response()->json(['status' => 'success', 'message' => 'Payment method deleted']);
    }
}
