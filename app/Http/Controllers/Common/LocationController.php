<?php

namespace App\Http\Controllers\Common;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

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
        $country = $request->query('country');
        if (!$country) {
            return response()->json(['status' => 'error', 'message' => 'Country is required'], 400);
        }

        return Cache::remember("states_list_{$country}", 86400, function () use ($country) {
            $response = Http::post('https://countriesnow.space/api/v0.1/countries/states', [
                'country' => $country
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                return response()->json([
                    'status' => 'success',
                    'data' => $data['data']['states'] ?? []
                ]);
            }
            return response()->json(['status' => 'error', 'data' => []], 500);
        });
    }
}
