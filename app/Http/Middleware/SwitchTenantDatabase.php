<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SwitchTenantDatabase
{
    /**
     * Handle an incoming request and switch MySQL connection dynamically
     * based on domain hostname or X-Tenant-Domain header.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $candidates = [];

        // 1. Explicit custom header
        if ($header = $request->header('X-Tenant-Domain')) {
            $candidates[] = trim($header);
        }

        // 2. X-Forwarded-Host header (sent by proxies or SSR Next.js requests)
        if ($forwardedHost = $request->header('X-Forwarded-Host')) {
            $candidates[] = trim($forwardedHost);
        }

        // 2. HTTP Origin header (Frontend Domain calling API)
        if ($origin = $request->header('Origin')) {
            $originHost = parse_url($origin, PHP_URL_HOST);
            $originPort = parse_url($origin, PHP_URL_PORT);

            if ($originHost && $originPort) {
                $candidates[] = $originHost . ':' . $originPort; // e.g. localhost:3001
            }
            if ($originHost) {
                $candidates[] = $originHost; // e.g. localhost
            }
        }

        // 3. HTTP Referer header
        if ($referer = $request->header('Referer')) {
            $refererHost = parse_url($referer, PHP_URL_HOST);
            $refererPort = parse_url($referer, PHP_URL_PORT);

            if ($refererHost && $refererPort) {
                $candidates[] = $refererHost . ':' . $refererPort;
            }
            if ($refererHost) {
                $candidates[] = $refererHost;
            }
        }

        // 4. Request Host header (including port if present)
        $httpHost = $request->getHttpHost(); // e.g., localhost:8000 or localhost:3001
        $candidates[] = $httpHost;

        $host = $request->getHost(); // e.g., localhost
        $candidates[] = $host;

        // 5. Host without 'api.' prefix (e.g., api.goldenmarkstore.com -> goldenmarkstore.com)
        if (str_starts_with($host, 'api.')) {
            $candidates[] = substr($host, 4);
        }

        $tenantDomains = config('tenants.domains', []);
        $databaseName = null;

        foreach ($candidates as $candidate) {
            if (!empty($candidate) && array_key_exists($candidate, $tenantDomains)) {
                $databaseName = $tenantDomains[$candidate];
                break;
            }
        }

        if (!$databaseName) {
            $databaseName = config('tenants.fallback', config('database.connections.mysql.database'));
        }

        // Dynamically reconfigure database connection
        $currentDb = Config::get('database.connections.mysql.database');

        if ($currentDb !== $databaseName) {
            Config::set('database.connections.mysql.database', $databaseName);
            DB::purge('mysql');
            DB::reconnect('mysql');
        }

        return $next($request);
    }
}
