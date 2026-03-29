<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Cors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->header('Origin');
        
        // Allowed origins strictly for development and production
        $allowedOrigins = [
            'http://localhost:3000',
            'http://localhost:3001',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:3001',
        ];

        // Determine which origin to send back
        $currentOrigin = '*'; // Default
        if ($origin && in_array($origin, $allowedOrigins)) {
            $currentOrigin = $origin;
        } elseif (count($allowedOrigins) > 0) {
           // If request has no origin but we want to be safe, pick first one or use '*'
           $currentOrigin = $allowedOrigins[0];
        }

        // Handle preflight OPTIONS request
        if ($request->isMethod('OPTIONS')) {
            return response()->json('OK', 200)
                ->header('Access-Control-Allow-Origin', $currentOrigin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN')
                ->header('Access-Control-Allow-Credentials', 'true');
        }

        $response = $next($request);

        // Ensure headers are on EVERY response
        if (method_exists($response, 'header')) {
            $response->header('Access-Control-Allow-Origin', 'FIXED_VALUE')
                     ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                     ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN')
                     ->header('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
