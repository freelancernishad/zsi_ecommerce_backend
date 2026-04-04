<?php

namespace App\Http\Controllers\Auth\User;

use App\Models\User;
use App\Mail\VerifyEmail;
use Illuminate\Http\Request;

use App\Mail\OtpNotification;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Utils\LogAction;


use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Services\Login\AppleAuthService;
use App\Http\Resources\LoginRegisterUserResource;
use App\Services\Login\GoogleAuthService;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Exceptions\JWTException;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ChangePasswordRequest;

class AuthUserController extends Controller
{
    /**
     * Register a new user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
public function register(RegisterRequest $request)
{

    // Create the user
    $user = User::create([
        'name' => $request->name,
        'email' => $request->email,
        'password' => Hash::make($request->password),
        'last_login_at' => now(),
        'is_blocked' => false,
        'is_active' => true,
        'role' => 'user',
    ]);
    // Log user registration
    LogAction::record(
        userId: $user->id,
        action: 'registration',
        description: 'User Registration',
        metadata: ['method' => 'register endpoint'],
        category: 'Authentication',
        isSuccess: true
    );

    // Generate a JWT token
    try {
        $token = JWTAuth::fromUser($user, ['guard' => 'user']);
    } catch (JWTException $e) {
        LogAction::record(
            userId: $user->id,
            action: 'jwt_token_failed',
            description: 'JWT Token Generation Failed',
            metadata: ['error' => $e->getMessage()],
            category: 'Authentication',
            isSuccess: false
        );
        return response()->json(['error' => 'Could not create token'], 500);
    }

    // Email verification handling
    $verify_url = $request->verify_url ?? null;
    $emailSent = true;
    $emailMessage = '';

    if ($verify_url) {
        try {
            Mail::to($user->email)->send(new VerifyEmail($user, $verify_url));
            $emailMessage = 'Registration successful. Verification email has been sent.';
        } catch (\Exception $e) {
            $emailSent = false;
            $emailMessage = 'Registration successful, but we could not send the verification email. Please try again later.';
            Log::error('User verification email failed: ' . $e->getMessage());
            LogAction::record(
                userId: $user->id,
                action: 'email_verification_failed',
                description: 'Email Verification Failed',
                metadata: ['error' => $e->getMessage()],
                category: 'Email',
                isSuccess: false
            );
        }
    } else {
        $otpResult = $this->sendOtp($user, $request, 'Registration');
        $emailSent = $otpResult['emailSent'];
        $emailMessage = $otpResult['emailMessage'];
    }

    return response()->json(new LoginRegisterUserResource($user, $token, $emailMessage, !$emailSent), 201);
}



    /**
     * Log in a user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
public function login(LoginRequest $request)
{
    // Handle Google login
    if ($request->access_token) {
        $googleAuthService = new GoogleAuthService();
        return $googleAuthService->login($request);
    }

    // Handle Apple login
    if ($request->identity_token) {
        $appleAuthService = new AppleAuthService();
        return $appleAuthService->login($request);
    }

    $credentials = $request->only('email', 'password');
    $user = User::where('email', $request->email)->first();

    // Check if user exists
    if (!$user) {
        return response()->json(['message' => 'User not found'], 401);
    }

    // Check if user is blocked
    if ($user->is_blocked) {
        LogAction::record(
            userId: $user->id,
            action: 'login_blocked',
            description: 'Login Blocked',
            metadata: ['reason' => 'User is blocked'],
            category: 'Authentication',
            isSuccess: false
        );

        return response()->json(['message' => 'Your account is blocked. Contact support.'], 403);
    }

    // Check if user is active
    if (!$user->is_active) {
        LogAction::record(
            userId: $user->id,
            action: 'login_inactive',
            description: 'Login Inactive',
            metadata: ['reason' => 'User account is inactive'],
            category: 'Authentication',
            isSuccess: false
        );

        return response()->json(['message' => 'Your account is not active.'], 403);
    }

    // Attempt authentication
    if (Auth::attempt($credentials)) {
        $user = Auth::user();

        // Check if email is verified
        if (!$user->hasVerifiedEmail()) {
            $otpResult = $this->sendOtp($user, $request, 'Login');

            LogAction::record(
                userId: $user->id,
                action: 'login_failed_unverified',
                description: 'Login Failed - Unverified',
                metadata: ['reason' => 'Email not verified, OTP sent'],
                category: 'Authentication',
                isSuccess: false
            );

            return response()->json([
                'message' => 'Email is not verified. ' . $otpResult['emailMessage'],
                'emailSent' => $otpResult['emailSent']
            ], 403);
        }

       $user->last_login_at = now();
        $user->save();

        try {
            $token = JWTAuth::fromUser($user, ['guard' => 'user']);
        } catch (JWTException $e) {
            LogAction::record(
                userId: $user->id,
                action: 'login_token_failed',
                description: 'Login Token Failed',
                metadata: ['reason' => 'JWT token generation failed'],
                category: 'Authentication',
                isSuccess: false
            );

            return response()->json(['error' => 'Could not create token'], 500);
        }

        // Log successful login
        LogAction::record(
            userId: $user->id,
            action: 'login_success',
            description: 'Login Successful',
            category: 'Authentication',
            isSuccess: true
        );



        return response()->json(new LoginRegisterUserResource($user, $token), 200);
    }

    // Log failed login
    LogAction::record(
        userId: $user->id,
        action: 'login_failed',
        description: 'Login Failed',
        metadata: ['reason' => 'Invalid credentials'],
        category: 'Authentication',
        isSuccess: false
    );

    return response()->json(['message' => 'Invalid credentials'], 401);
}



    /**
     * Get the authenticated user.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        $user = Auth::user();
        $user->load(['planSubscriptions' => function ($query) {
            $query->where('status', 'active')->latest('start_date')->limit(1)->with('plan');
        }]);
        
        $user->active_plan = $user->planSubscriptions->first();
        unset($user->planSubscriptions); // Clean up response

        return response()->json($user);
    }

    /**
     * Log out the authenticated user.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        try {
            $token = JWTAuth::getToken();
            if (!$token) {
                LogAction::record(
                    userId: Auth::id() ?? null,
                    action: 'logout_no_token',
                    description: 'Logout Attempt Without Token',
                    metadata: ['reason' => 'Token not provided'],
                    category: 'Authentication',
                    isSuccess: false
                );

                return response()->json([
                    'success' => false,
                    'message' => 'Token not provided.'
                ], 401);
            }



            LogAction::record(
                userId: Auth::id(),
                action: 'logout',
                description: 'User Logout',
                metadata: ['token' => (string)$token],
                category: 'Authentication',
                isSuccess: true
            );
            if (config('jwt.blacklist_enabled')) {
                JWTAuth::invalidate($token);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully.'
            ], 200);
        } catch (JWTException $e) {
            LogAction::record(
                userId: Auth::id() ?? null,
                action: 'logout_failed',
                description: 'Logout Failed',
                metadata: ['error' => $e->getMessage()],
                category: 'Authentication',
                isSuccess: false
            );

            return response()->json([
                'success' => false,
                'message' => 'Failed to logout, token invalidation failed: ' . $e->getMessage()
            ], 500);
        }
    }


  /**
     * Change the password of the authenticated user.
     */
  public function changePassword(ChangePasswordRequest $request)
{

    $authUser = Auth::user();
    $user = User::find($authUser->id);

    // Check if the current password matches
    if (!Hash::check($request->current_password, $user->password)) {
        LogAction::record(
            userId: $user->id,
            action: 'password_change_failed',
            description: 'Password Change Failed (Wrong Current Password)',
            metadata: ['reason' => 'Current password mismatch'],
            category: 'Account',
            isSuccess: false
        );

        return response()->json(['message' => 'Current password is incorrect.'], 400);
    }

    // Update the password
    $user->password = Hash::make($request->new_password);
    $user->save();

    LogAction::record(
        userId: $user->id,
        action: 'password_changed',
        description: 'Password Changed Successfully',
        category: 'Account',
        isSuccess: true
    );

    return response()->json(['message' => 'Password updated successfully.']);
}


    /**
     * Check if a JWT token is valid.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkToken(Request $request)
    {
        $token = $request->bearerToken(); // Get the token from the Authorization header

        if (!$token) {
            return response()->json(['message' => 'Token not provided.'], 400);
        }

        try {
            // Authenticate the token and retrieve the authenticated user
            $user = JWTAuth::setToken($token)->authenticate();

            if (!$user) {
                return response()->json(['message' => 'Token is invalid or user not found.'], 401);
            }

            $payload = [
                'email' => $user->email,
                'name' => $user->name,
                'email_verified' => $user->hasVerifiedEmail(), // Checks verification status
            ];

            return response()->json(['message' => 'Token is valid.','user'=>$payload], 200);
        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            return response()->json(['message' => 'Token has expired.'], 401);
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            return response()->json(['message' => 'Token is invalid.'], 401);
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            return response()->json(['message' => 'Token is missing or malformed.'], 401);
        }
    }

    /**
     * Helper function to generate and send OTP.
     *
     * @param User $user
     * @param Request $request
     * @param string $context
     * @return array
     */
    private function sendOtp(User $user, Request $request, $context = 'Registration')
    {
        $otp = random_int(100000, 999999);
        $user->otp = Hash::make($otp);
        $user->otp_expires_at = now()->addMinutes(5);
        $user->save();

        $emailSent = true;
        
        $contextMessage = $context === 'Registration' ? 'Registration successful.' : 'Login attempt.';

        try {
            Mail::to($user->email)->send(new OtpNotification($otp));
            $emailMessage = $contextMessage . ' OTP has been sent to your email.';
        } catch (\Exception $e) {
            $emailSent = false;
            $emailMessage = $contextMessage . ' We could not send the OTP. Please try again later.';
            Log::error('User OTP email failed: ' . $e->getMessage());
            LogAction::record(
                userId: $user->id,
                action: 'otp_email_failed',
                description: 'OTP Email Failed',
                metadata: ['error' => $e->getMessage()],
                category: 'Email',
                isSuccess: false
            );
        }

        return [
            'emailSent' => $emailSent,
            'emailMessage' => $emailMessage
        ];
    }
}
