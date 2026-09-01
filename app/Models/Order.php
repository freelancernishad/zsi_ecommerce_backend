<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number', 'user_id', 'original_subtotal', 'subtotal', 'discount', 
        'coupon_code', 'coupon_type', 'coupon_value',
        'shipping_cost', 'tax', 'tax_rate', 'tax_details', 'total', 'status', 
        'payment_method', 'payment_method_name', 'payment_gateway', 'transaction_id', 'payment_details',
        'shipping_method', 'shipping_address_id',
        'shipping_first_name', 'shipping_last_name', 'shipping_email', 
        'shipping_phone', 'shipping_country', 'shipping_street_address', 
        'shipping_city', 'shipping_state', 'shipping_zip_code',
        'payment_status', 'fulfillment_status', 'delivery_status',
        'courier_name', 'tracking_number', 'tracking_url', 'shipped_at',
        'cj_order_id', 'cj_order_status', 'cj_tracking_number'
    ];

    protected $casts = [
        'tax_details' => 'array',
        'tax_rate' => 'float',
        'tax' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at', 'desc');
    }

    public function shippingAddress()
    {
        return $this->belongsTo(Address::class, 'shipping_address_id');
    }
}
