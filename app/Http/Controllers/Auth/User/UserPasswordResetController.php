<?php

namespace App\Http\Controllers\Auth\User;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Mail\PasswordResetMail;
use App\Mail\OtpNotification;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Validator;
use App\Http\Requests\Auth\SendResetLinkRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;

class UserPasswordResetController extends Controller
{
    /**
     * Send a password reset link to the user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */


    public function sendResetLinkEmail(SendResetLinkRequest $request)
    {

        $email = $request->input('email');
        $resetUrlBase = $request->input('redirect_url');

        // Find the user by email
        $user = User::where('email', $email)->first();

        // Send the password reset link
        $response = Password::sendResetLink(
            $request->only('email'),
            function ($user, $token) use ($resetUrlBase) {
                // Create the full reset URL
                $resetUrl = "{$resetUrlBase}?token={$token}&email={$user->email}";

                // Send the email
                Mail::to($user->email)->send(new PasswordResetMail($user, $resetUrl));
            }
        );

        // Return response based on whether the reset link was sent
        if ($response == Password::RESET_LINK_SENT) {
            return response()->json([
                'status' => __($response),
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email
                ]
            ], 200);
        } else {
            return response()->json(['error' => __($response)], 400);
        }
    }



    /**
     * Reset the user password.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function reset(ResetPasswordRequest $request)
    {

        $response = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->password = Hash::make($password);
                $user->save();

                event(new PasswordReset($user));
            }
        );

        return $response == Password::PASSWORD_RESET
            ? response()->json(['message' => 'Password has been reset successfully.'])
            : response()->json(['error' => 'Unable to reset password.'], 500);
    }

    /**
     * Send a 6-digit OTP to the user for password reset.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'redirect_url' => 'required|url',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $email = $request->input('email');
        $redirectUrlBase = $request->input('redirect_url');
        $user = User::where('email', $email)->first();

        if (!$user) {
            return response()->json(['error' => 'User not found.'], 404);
        }

        // Generate a password reset token
        $token = Password::createToken($user);
        $resetUrl = "{$redirectUrlBase}?token={$token}&email=" . urlencode($user->email);

        // Generate a 6-digit OTP
        $otp = random_int(100000, 999999);
        Log::info("Generated OTP for {$user->email}: {$otp}");
        Log::info("Generated Reset Link for {$user->email}: {$resetUrl}");

        $user->otp = Hash::make($otp);
        $user->otp_expires_at = now()->addMinutes(10);
        $user->save();

        // Send OTP & Link email
        try {
            Mail::to($user->email)->send(new PasswordResetMail($user, $resetUrl, $otp));
            return response()->json([
                'message' => 'OTP and Password Reset Link have been sent to your email.',
                'email' => $user->email
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to send password reset OTP & link email: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to send reset email. Please try again later.'], 500);
        }
    }

    /**
     * Verify OTP and reset the user's password.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyOtpAndReset(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|digits:6',
            'password' => 'required|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $email = $request->input('email');
        $user = User::where('email', $email)->first();

        if (!$user) {
            return response()->json(['error' => 'User not found.'], 404);
        }

        // Check if OTP is expired
        if (!$user->otp || !$user->otp_expires_at || $user->otp_expires_at < now()) {
            return response()->json(['error' => 'OTP has expired. Please request a new one.'], 400);
        }

        // Verify OTP
        if (!Hash::check($request->otp, $user->otp)) {
            return response()->json(['error' => 'Invalid OTP code.'], 400);
        }

        // Reset password
        $user->password = Hash::make($request->password);
        $user->otp = null;
        $user->otp_expires_at = null;
        $user->save();

        return response()->json([
            'message' => 'Your password has been reset successfully.'
        ], 200);
    }
}
