<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Company;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Traits\ApiResponse;

class AuthController extends Controller
{
    use ApiResponse;

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'required|string|max:20',
            'tax_id' => 'nullable|string|max:50',
            'plan_id' => 'nullable|exists:plans,id'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        try {
            DB::beginTransaction();

            // Create company
            $company = Company::create([
                'name' => $request->company_name,
                'legal_name' => $request->company_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'tax_id' => $request->tax_id,
                'status' => true,
                'plan_id' => $request->plan_id ?? Plan::where('code', 'starter')->first()->id,
                'subscription_ends_at' => now()->addDays(14) // Trial period
            ]);

            // Create user
            $user = User::create([
                'company_id' => $company->id,
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'status' => true
            ]);

            // Assign admin role
            $user->assignRole('admin');

            // Create default store
            $company->store()->create([
                'name' => $company->name,
                'slug' => \Str::slug($company->name),
                'description' => 'Loja online da ' . $company->name,
                'status' => true,
                'settings' => [
                    'theme' => 'default',
                    'primary_color' => '#4361ee',
                    'whatsapp_enabled' => true,
                    'whatsapp_number' => $company->phone
                ]
            ]);

            // Create default categories
            $defaultCategories = ['Geral', 'Sem Categoria'];
            foreach ($defaultCategories as $categoryName) {
                $company->categories()->create([
                    'name' => $categoryName,
                    'slug' => \Str::slug($categoryName)
                ]);
            }

            DB::commit();

            // Generate token
            $token = JWTAuth::fromUser($user);

            return $this->successResponse([
                'user' => $user->load('roles'),
                'company' => $company,
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60
            ], 'Registration successful', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Registration failed: ' . $e->getMessage(), 500);
        }
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $credentials = $request->only('email', 'password');

        if (!$token = JWTAuth::attempt($credentials)) {
            return $this->errorResponse('Invalid credentials', 401);
        }

        $user = Auth::user();
        
        // Check if user is active
        if (!$user->status) {
            return $this->errorResponse('Account is disabled', 403);
        }

        // Check if company is active
        if (!$user->company->isActive()) {
            return $this->errorResponse('Company subscription has expired', 403);
        }

        // Update last login
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip()
        ]);

        return $this->successResponse([
            'user' => $user->load('roles'),
            'company' => $user->company,
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => config('jwt.ttl') * 60
        ], 'Login successful');
    }

    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return $this->successResponse([], 'Successfully logged out');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to logout', 500);
        }
    }

    public function refresh()
    {
        try {
            $token = JWTAuth::refresh(JWTAuth::getToken());
            return $this->successResponse([
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60
            ], 'Token refreshed');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to refresh token', 401);
        }
    }

    public function me()
    {
        $user = Auth::user()->load(['company', 'roles']);
        return $this->successResponse([
            'user' => $user,
            'company' => $user->company,
            'permissions' => $user->getAllPermissions()->pluck('name')
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'phone' => 'required|string|max:20',
            'avatar' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $user->update($request->only(['name', 'email', 'phone', 'avatar']));

        return $this->successResponse([
            'user' => $user->fresh()->load('roles')
        ], 'Profile updated successfully');
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $user = Auth::user();

        if (!Hash::check($request->current_password, $user->password)) {
            return $this->errorResponse('Current password is incorrect', 422);
        }

        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        return $this->successResponse([], 'Password changed successfully');
    }

    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        // Generate reset token (in production, use Laravel's built-in password reset)
        $token = \Str::random(60);
        
        // Store token in cache for 1 hour
        \Cache::put('password_reset_' . $request->email, $token, now()->addHour());

        // Send email (simulated)
        // Mail::to($request->email)->send(new PasswordResetMail($token));

        return $this->successResponse([], 'Password reset link sent to your email');
    }

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $cachedToken = \Cache::get('password_reset_' . $request->email);

        if (!$cachedToken || $cachedToken !== $request->token) {
            return $this->errorResponse('Invalid or expired token', 422);
        }

        $user = User::where('email', $request->email)->first();
        $user->update([
            'password' => Hash::make($request->password)
        ]);

        // Clear token
        \Cache::forget('password_reset_' . $request->email);

        return $this->successResponse([], 'Password reset successfully');
    }
}
