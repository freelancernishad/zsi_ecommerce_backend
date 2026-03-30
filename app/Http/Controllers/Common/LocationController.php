<?php

namespace App\Http\Controllers\Common;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class LocationController extends Controller
{
    /**
     * Fetch all countries
     */
    public function getCountries()
    {
        return Cache::remember('countries_list_full', 86400, function () {
            $response = Http::get('https://countriesnow.space/api/v0.1/countries/iso');
            if ($response->successful()) {
                $data = $response->json();
                return response()->json([
                    'status' => 'success',
                    'data' => $data['data'] ?? []
                ]);
            }
            return response()->json(['status' => 'error', 'data' => []], 500);
        });
    }

    /**
     * Fetch states for a given country
     */
    public function getStates(Request $request)
    {
        $countryInput = $request->query('country');
        if (!$countryInput) {
            return response()->json([
                'status' => 'error', 
                'message' => 'Country is required',
                'isError' => true
            ], 400);
        }

        // Map common ISO codes to full names for external API compatibility
        $mapping = [
            'USA' => 'United States',
            'US'  => 'United States',
            'UK'  => 'United Kingdom',
            'BD'  => 'Bangladesh',
            'UAE' => 'United Arab Emirates',
            'KSA' => 'Saudi Arabia',
        ];

        $country = $mapping[strtoupper($countryInput)] ?? $countryInput;
        $cacheKey = "states_list_" . Str::slug($country);

        // Check if cached
        if (Cache::has($cacheKey)) {
            return response()->json([
                'status' => 'success',
                'data' => Cache::get($cacheKey)
            ]);
        }

        try {
            $response = Http::post('https://countriesnow.space/api/v0.1/countries/states', [
                'country' => $country
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                $states = $data['data']['states'] ?? [];
                
                // Only cache successful responses
                Cache::put($cacheKey, $states, 86400);

                return response()->json([
                    'status' => 'success',
                    'data' => $states
                ]);
            }

            $errorData = $response->json();
            return response()->json([
                'status' => 'error', 
                'message' => $errorData['msg'] ?? 'Country not found or provider error',
                'isError' => true,
                'data' => []
            ], $response->status() >= 400 && $response->status() < 600 ? $response->status() : 500);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error', 
                'message' => 'Failed to connect to location provider',
                'isError' => true,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch couriers for a given country
     */
    public function getCouriers(Request $request)
    {
        $country = $request->query('country');
        $countryUpper = strtoupper($country);

        // Curated List of Couriers with Tracking URL Templates
        $couriers = [
            'USA' => [
                ['name' => 'FedEx', 'url' => 'https://www.fedex.com/fedextrack/?trknbr={id}'],
                ['name' => 'DHL Express', 'url' => 'https://www.dhl.com/en/express/tracking.html?AWB={id}'],
                ['name' => 'UPS', 'url' => 'https://www.ups.com/track?loc=en_US&tracknum={id}'],
                ['name' => 'USPS', 'url' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels={id}'],
            ],
            'BD' => [
                ['name' => 'Pathao', 'url' => 'https://pathao.com/courier/tracking?consignment_id={id}'],
                ['name' => 'RedX', 'url' => 'https://redx.com.bd/track?trackingId={id}'],
                ['name' => 'Steadfast Courier', 'url' => 'https://steadfast.com.bd/t/{id}'],
                ['name' => 'Paperfly', 'url' => 'https://www.paperfly.com.bd/delivery-order-tracking.php?order_id={id}'],
                ['name' => 'Sundarban Courier', 'url' => 'https://sundarbancourierltd.com/tracking?cn={id}'],
            ],
            'INTERNATIONAL' => [
                ['name' => 'Aramex', 'url' => 'https://www.aramex.com/express/track-results?ShipmentNumber={id}'],
                ['name' => 'DPD', 'url' => 'https://www.dpd.com/tracking/(lang)/en_US?parcel={id}'],
            ]
        ];

        $suggested = [];
        if ($countryUpper === 'USA' || $countryUpper === 'UNITED STATES') {
            $suggested = $couriers['USA'];
        } elseif ($countryUpper === 'BD' || $countryUpper === 'BANGLADESH') {
            $suggested = $couriers['BD'];
        } else {
            $suggested = array_merge($couriers['USA'], $couriers['BD'], $couriers['INTERNATIONAL']);
        }

        return response()->json([
            'status' => 'success',
            'data' => $suggested
        ]);
    }
}
