<?php

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with(['user', 'items.product', 'shippingAddress'])
            ->withCount('items');

        // Filter by Status (Combined mapping for delivery_status or general status)
        if ($request->has('status') && $request->status !== 'all') {
            $status = $request->status;
            // Map the tab labels to actual column values
            if (in_array($status, ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])) {
                $query->where('delivery_status', $status);
            } elseif ($status === 'in_progress') {
                $query->whereIn('delivery_status', ['pending', 'processing']);
            }
        }

        // Search by Order Number or Customer Name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhereHas('user', function($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 8);
        $orders = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $orders
        ]);
    }

    public function stats()
    {
        $stats = [
            'items_ordered' => DB::table('order_items')->sum('quantity'),
            'fulfilled' => Order::where('fulfillment_status', 'fulfilled')->count(),
            'cancelled' => Order::where('delivery_status', 'cancelled')->count(),
            'completed' => Order::where('delivery_status', 'delivered')->count(),
            'returns_loss' => Order::where('delivery_status', 'returned')->sum('total'),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    public function show($id)
    {
        $order = Order::with(['user', 'items.product', 'items.variant', 'shippingAddress', 'statusHistories'])->findOrFail($id);
        return response()->json(['status' => 'success', 'data' => $order]);
    }

    public function update(Request $request, $id)
    {
        $order = Order::with('items')->findOrFail($id);

        $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:order_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.regular_price' => 'nullable|numeric|min:0',
            'items.*.discount_price' => 'nullable|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            // Update items
            foreach ($request->items as $itemData) {
                $item = $order->items()->find($itemData['id']);
                if ($item) {
                    $reg = $itemData['regular_price'] ?? $item->regular_price ?? $itemData['price'];
                    $disc = $itemData['discount_price'] ?? $item->discount_price ?? 0;
                    $sellingPrice = max(0, $reg - $disc);

                    $item->update([
                        'quantity' => $itemData['quantity'],
                        'price' => $sellingPrice,
                        'regular_price' => $reg,
                        'discount_price' => $disc,
                    ]);
                }
            }

            // Recalculate Subtotal based on the updated items
            $subtotal = $order->items()->sum(DB::raw('quantity * price'));

            // Get Tax Rate for the order's state
            $taxRate = 0;
            if ($order->shipping_state && $order->shipping_country) {
                $taxRecord = DB::table('taxes')
                    ->where('country', $order->shipping_country)
                    ->where('state', $order->shipping_state)
                    ->first();
                
                if ($taxRecord) {
                    $taxRate = $taxRecord->tax_rate;
                }
            }

            $taxAmount = ($subtotal * $taxRate) / 100;
            $shippingCost = $request->get('shipping_cost', $order->shipping_cost);
            $discount = $request->get('discount', $order->discount);
            $total = $subtotal + $taxAmount + $shippingCost - $discount;

            // Update Order Totals
            $order->update([
                'subtotal' => $subtotal,
                'tax' => $taxAmount,
                'shipping_cost' => $shippingCost,
                'discount' => $discount,
                'total' => $total,
            ]);

            DB::commit();
            return response()->json([
                'status' => 'success',
                'message' => 'Order updated and recalculated successfully',
                'data' => $order->load('items')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update order: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'payment_status' => 'nullable|string',
            'fulfillment_status' => 'nullable|string',
            'delivery_status' => 'nullable|string',
            'status' => 'nullable|string',
            'courier_name' => 'nullable|string',
            'tracking_number' => 'nullable|string',
            'tracking_url' => 'nullable|string',
            'shipped_at' => 'nullable|date',
        ]);

        $order = Order::findOrFail($id);
        
        $updateData = $request->only([
            'payment_status', 'fulfillment_status', 'delivery_status', 'status',
            'courier_name', 'tracking_number', 'tracking_url', 'shipped_at'
        ]);

        // Auto-set shipped_at if delivery_status is 'shipped' and not already set
        if (($request->delivery_status === 'shipped' || $request->status === 'shipped') && !$order->shipped_at) {
            $updateData['shipped_at'] = now();
        }

        $order->update($updateData);

        return response()->json([
            'status' => 'success', 
            'message' => "Order status/tracking updated successfully",
            'data' => $order
        ]);
    }

    public function addStatusHistory(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string',
            'location' => 'nullable|string',
            'description' => 'nullable|string',
        ]);

        $order = Order::findOrFail($id);

        $history = $order->statusHistories()->create([
            'status' => $request->status,
            'location' => $request->location,
            'description' => $request->description,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Order tracking log added successfully",
            'data' => $history
        ]);
    }
}
