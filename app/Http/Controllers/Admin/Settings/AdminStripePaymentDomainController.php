<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\TenantDatabaseManager;
use Illuminate\Http\Request;
use Stripe\StripeClient;

class AdminStripePaymentDomainController extends Controller
{
    private function resolveStripeSecretKey(): ?string
    {
        $secretKey = SiteSetting::where('key', 'stripe_secret_key')->value('value')
            ?: config('services.stripe.secret');

        if (!is_string($secretKey)) {
            return null;
        }

        $trimmed = trim($secretKey);
        if ($trimmed === '' || (!str_starts_with($trimmed, 'sk_') && !str_starts_with($trimmed, 'rk_'))) {
            return null;
        }

        return $trimmed;
    }

    public static function normalizePublicDomain(?string $raw): ?string
    {
        $normalized = TenantDatabaseManager::normalizeDomain($raw);
        if (!$normalized) {
            return null;
        }

        if (str_contains($normalized, ':')) {
            $normalized = explode(':', $normalized)[0];
        }

        $normalized = trim($normalized, ". \t\n\r\0\x0B");
        if ($normalized === '' || !str_contains($normalized, '.')) {
            return null;
        }

        if (
            $normalized === 'localhost' ||
            filter_var($normalized, FILTER_VALIDATE_IP) !== false ||
            str_ends_with($normalized, '.test') ||
            str_ends_with($normalized, '.local') ||
            str_starts_with($normalized, 'admin.') ||
            str_starts_with($normalized, 'api.') ||
            $normalized === 'ecommerce-admin-zsi.vercel.app'
        ) {
            return null;
        }

        return $normalized;
    }

    public static function detectTenantStorefrontDomains(Request $request): array
    {
        $currentDb = TenantDatabaseManager::currentDatabase();
        $tenantDomainsMap = TenantDatabaseManager::tenantDomainsMap();

        // Extract root storefront domain from request headers if present (e.g. admin.goldenmarkstore.com -> goldenmarkstore.com)
        $requestRootDomain = null;
        $headerCandidates = [
            $request->header('X-Tenant-Domain'),
            $request->header('Origin'),
            $request->header('Referer'),
        ];
        foreach ($headerCandidates as $rawHeader) {
            $candidate = TenantDatabaseManager::normalizeDomain(is_string($rawHeader) ? $rawHeader : null);
            if (!$candidate) {
                continue;
            }
            if (str_contains($candidate, ':')) {
                $candidate = explode(':', $candidate)[0];
            }
            if (str_starts_with($candidate, 'admin.')) {
                $candidate = substr($candidate, 6);
            } elseif (str_starts_with($candidate, 'api.')) {
                $candidate = substr($candidate, 4);
            }
            if (str_starts_with($candidate, 'www.')) {
                $candidate = substr($candidate, 4);
            }
            $clean = self::normalizePublicDomain($candidate);
            if ($clean !== null) {
                $requestRootDomain = $clean;
                break;
            }
        }

        $detected = [];
        foreach ($tenantDomainsMap as $domain => $dbName) {
            if ($dbName === $currentDb) {
                $clean = self::normalizePublicDomain($domain);
                if ($clean !== null) {
                    $detected[] = $clean;
                }
            }
        }

        if ($requestRootDomain !== null) {
            $scoped = array_values(array_filter(
                $detected,
                fn (string $d) => $d === $requestRootDomain || str_ends_with($d, '.' . $requestRootDomain)
            ));
            if (!empty($scoped)) {
                return array_values(array_unique($scoped));
            }

            return array_values(array_unique([
                $requestRootDomain,
                'www.' . $requestRootDomain,
            ]));
        }

        return array_values(array_unique($detected));
    }

    private function formatDomainObject($pmd): array
    {
        return [
            'id' => $pmd->id,
            'domain_name' => $pmd->domain_name,
            'enabled' => (bool) ($pmd->enabled ?? false),
            'livemode' => (bool) ($pmd->livemode ?? false),
            'apple_pay_status' => $pmd->apple_pay->status ?? 'inactive',
            'apple_pay_error' => $pmd->apple_pay->status_details->error_message ?? null,
            'google_pay_status' => $pmd->google_pay->status ?? 'inactive',
            'link_status' => $pmd->link->status ?? 'inactive',
            'amazon_pay_status' => $pmd->amazon_pay->status ?? 'inactive',
        ];
    }

    public function syncDomainsForCurrentTenant(Request $request, array $requestedDomains = []): array
    {
        $detectedDomains = self::detectTenantStorefrontDomains($request);
        $secretKey = $this->resolveStripeSecretKey();

        if (!$secretKey) {
            return [
                'stripe_configured' => false,
                'detected_domains' => $detectedDomains,
                'results' => [],
                'registered_domains' => [],
            ];
        }

        $targetDomains = [];
        if (!empty($requestedDomains)) {
            foreach ($requestedDomains as $raw) {
                $clean = self::normalizePublicDomain(is_string($raw) ? $raw : null);
                if ($clean !== null) {
                    $targetDomains[] = $clean;
                }
            }
            $targetDomains = array_values(array_unique($targetDomains));
        } else {
            $targetDomains = $detectedDomains;
        }

        $stripe = new StripeClient($secretKey);
        $existingCollection = $stripe->paymentMethodDomains->all(['limit' => 100]);
        $existingByDomain = [];
        foreach ($existingCollection->data as $existingDomain) {
            $existingByDomain[strtolower($existingDomain->domain_name)] = $existingDomain;
        }

        $results = [];
        foreach ($targetDomains as $domain) {
            try {
                if (isset($existingByDomain[$domain])) {
                    $existing = $existingByDomain[$domain];
                    if (!$existing->enabled) {
                        $stripe->paymentMethodDomains->update($existing->id, ['enabled' => true]);
                    }
                    $validated = $stripe->paymentMethodDomains->validate($existing->id, []);
                    $results[] = $this->formatDomainObject($validated);
                } else {
                    $created = $stripe->paymentMethodDomains->create([
                        'domain_name' => $domain,
                        'enabled' => true,
                    ]);
                    if (($created->apple_pay->status ?? '') !== 'active') {
                        try {
                            $created = $stripe->paymentMethodDomains->validate($created->id, []);
                        } catch (\Throwable $e) {
                            // Preserve created object status/details even if immediate validation throws
                        }
                    }
                    $results[] = $this->formatDomainObject($created);
                }
            } catch (\Throwable $e) {
                $results[] = [
                    'domain_name' => $domain,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $refreshedCollection = $stripe->paymentMethodDomains->all(['limit' => 100]);
        $registeredDomains = array_map(
            fn ($pmd) => $this->formatDomainObject($pmd),
            $refreshedCollection->data
        );

        return [
            'stripe_configured' => true,
            'detected_domains' => $detectedDomains,
            'results' => $results,
            'registered_domains' => array_values($registeredDomains),
        ];
    }

    public function index(Request $request)
    {
        $detectedDomains = self::detectTenantStorefrontDomains($request);
        $secretKey = $this->resolveStripeSecretKey();

        if (!$secretKey) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'stripe_configured' => false,
                    'detected_domains' => $detectedDomains,
                    'registered_domains' => [],
                ],
            ]);
        }

        try {
            $stripe = new StripeClient($secretKey);
            $domains = $stripe->paymentMethodDomains->all(['limit' => 100]);
            $registeredDomains = array_map(
                fn ($pmd) => $this->formatDomainObject($pmd),
                $domains->data
            );

            return response()->json([
                'status' => 'success',
                'data' => [
                    'stripe_configured' => true,
                    'detected_domains' => $detectedDomains,
                    'registered_domains' => array_values($registeredDomains),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => [
                    'stripe_configured' => true,
                    'detected_domains' => $detectedDomains,
                    'registered_domains' => [],
                ],
            ], 400);
        }
    }

    public function register(Request $request)
    {
        $request->validate([
            'domain_name' => 'nullable|string|max:255',
            'domains' => 'nullable|array',
            'domains.*' => 'string|max:255',
        ]);

        if (!$this->resolveStripeSecretKey()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Stripe Secret Key is not configured yet.',
            ], 422);
        }

        $requestedDomains = [];
        if ($request->filled('domain_name')) {
            $requestedDomains[] = (string) $request->input('domain_name');
        }
        if (is_array($request->input('domains'))) {
            foreach ($request->input('domains') as $d) {
                $requestedDomains[] = (string) $d;
            }
        }

        if (!empty($requestedDomains)) {
            $validRequested = array_filter(
                array_map(fn ($d) => self::normalizePublicDomain($d), $requestedDomains)
            );
            if (empty($validRequested)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Please enter a valid public storefront domain (e.g. goldenmarkstore.com).',
                ], 422);
            }
        }

        try {
            $syncResult = $this->syncDomainsForCurrentTenant($request, $requestedDomains);

            if (empty($requestedDomains) && empty($syncResult['detected_domains'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No public storefront domains detected for this tenant. Please enter a custom domain to verify.',
                    'data' => $syncResult,
                ], 422);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Stripe payment method domains synced and validated.',
                'data' => $syncResult,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function validateDomain(string $id)
    {
        $secretKey = $this->resolveStripeSecretKey();
        if (!$secretKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'Stripe Secret Key is not configured yet.',
            ], 422);
        }

        try {
            $stripe = new StripeClient($secretKey);
            $validated = $stripe->paymentMethodDomains->validate($id, []);

            return response()->json([
                'status' => 'success',
                'message' => 'Domain validation check completed.',
                'data' => $this->formatDomainObject($validated),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
