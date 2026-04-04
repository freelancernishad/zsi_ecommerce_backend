<?php

namespace App\Services\Login;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Facades\Validator;

class GoogleAuthService
{
    public function login(Request $request)
    {
        try {
            $accessToken = $request->access_token;
            $code = $request->code;

            // If we have a code (from redirect flow), exchange it for an access token
            if ($code) {
                $clientId = \App\Models\SystemSetting::getValue('google_client_id');
                $clientSecret = \App\Models\SystemSetting::getValue('google_client_secret');
                
                // Use the redirect_uri from request, or fallback to frontend origin
                $redirectUri = $request->redirect_uri;
                
                if (!$redirectUri) {
                    $frontendUrl = env('FRONTEND_URL', 'http://localhost:3001');
                    $path = $request->header('referer') ? parse_url($request->header('referer'), PHP_URL_PATH) : '/register';
                    $redirectUri = rtrim($frontendUrl, '/') . $path;
                }

                $tokenResponse = Http::post('https://oauth2.googleapis.com/token', [
                    'code' => $code,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $redirectUri,
                    'grant_type' => 'authorization_code',
                ]);

                if ($tokenResponse->failed()) {
                    return response()->json([
                        'error' => 'Failed to exchange Google orientation code.',
                        'details' => $tokenResponse->json()
                    ], 400);
                }

                $accessToken = $tokenResponse->json()['access_token'];
            }

            if (!$accessToken) {
                return response()->json([
                    'error' => 'No access token or code provided.',
                ], 400);
            }

            // Fetch user data from Google API
            $response = Http::get('https://www.googleapis.com/oauth2/v3/userinfo', [
                'access_token' => $accessToken,
            ]);

            if ($response->failed() || !isset($response['email'])) {
                return response()->json([
                    'error' => 'Invalid access token or Google API error.',
                ], 400);
            }

            $userData = $response->json();
            $user = User::where('email', $userData['email'])->first();

            if (!$user) {
                // Register the user if they don't exist
                $user = User::create([
                    'name' => $userData['name'] ?? explode('@', $userData['email'])[0],
                    'email' => $userData['email'],
                    'password' => Hash::make(Str::random(16)), // Random password
                    'email_verified_at' => now(),
                ]);
            } else {
                $user->update(['email_verified_at'=> now()]);
            }

            // Authenticate the user
            Auth::login($user);

            // Payload for JWT (can be used for additional claims if needed)
            $userPayload = [
                'email' => $user->email,
                'name' => $user->name,
                'category' => $user->category ?? 'default',
                'email_verified' => $user->hasVerifiedEmail(),
            ];

            try {
                $token = JWTAuth::fromUser($user, ['guard' => 'user']);
            } catch (JWTException $e) {
                return response()->json([
                    'error' => 'Could not create JWT token',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'token' => $token,
                'user' => $userPayload,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred during Google authentication.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
