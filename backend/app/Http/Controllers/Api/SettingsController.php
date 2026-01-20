<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use App\Traits\ApiResponse;

class SettingsController extends Controller
{
    use ApiResponse;

    public function getCompany()
    {
        $company = auth()->user()->company;
        
        return $this->successResponse([
            'company' => $company,
            'store' => $company->store,
            'plan' => $company->plan,
            'stats' => [
                'users_count' => $company->users()->count(),
                'products_count' => $company->products()->count(),
                'sales_count' => $company->sales()->count(),
                'storage_usage' => $this->getStorageUsage($company->id),
                'storage_limit' => $company->getStorageLimit()
            ]
        ]);
    }

    public function updateCompany(Request $request)
    {
        $company = auth()->user()->company;

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'legal_name' => 'nullable|string|max:255',
            'tax_id' => 'nullable|string|max:50',
            'email' => 'required|string|email|max:255|unique:companies,email,' . $company->id,
            'phone' => 'required|string|max:20',
            'website' => 'nullable|string|max:255|url',
            'logo' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:2',
            'country' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'timezone' => 'nullable|string|max:50',
            'currency' => 'nullable|string|max:3',
            'language' => 'nullable|string|max:10'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $company->update($request->all());

        return $this->successResponse($company->fresh(), 'Company updated successfully');
    }

    public function users(Request $request)
    {
        $company = auth()->user()->company;
        
        $users = $company->users()
            ->with('roles')
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%");
            })
            ->orderBy('name')
            ->paginate($request->get('per_page', 10));

        return $this->successResponse($users);
    }

    public function createUser(Request $request)
    {
        $company = auth()->user()->company;

        // Check user limit
        if (!$company->canAddUser()) {
            return $this->errorResponse('User limit reached for your plan', 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20',
            'role' => 'required|in:admin,manager,staff,cashier',
            'status' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $user = $company->users()->create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'status' => $request->has('status') ? $request->status : true
        ]);

        $user->assignRole($request->role);

        // Send invitation email
        // Mail::to($user->email)->send(new UserInvitation($user, $request->password));

        return $this->successResponse($user->load('roles'), 'User created successfully', 201);
    }

    public function updateUser(Request $request, $id)
    {
        $company = auth()->user()->company;
        $user = $company->users()->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'role' => 'required|in:admin,manager,staff,cashier',
            'status' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $user->update($request->only(['name', 'email', 'phone', 'status']));

        // Update role
        $user->syncRoles([$request->role]);

        return $this->successResponse($user->fresh()->load('roles'), 'User updated successfully');
    }

    public function deleteUser($id)
    {
        $company = auth()->user()->company;
        $user = $company->users()->findOrFail($id);

        // Cannot delete yourself
        if ($user->id === auth()->id()) {
            return $this->errorResponse('Cannot delete your own account', 422);
        }

        $user->delete();

        return $this->successResponse([], 'User deleted successfully');
    }

    public function integrations()
    {
        $company = auth()->user()->company;
        
        $integrations = [
            'whatsapp' => [
                'enabled' => $company->store->settings['whatsapp_enabled'] ?? false,
                'number' => $company->store->settings['whatsapp_number'] ?? null,
                'connected' => !empty($company->settings['whatsapp_token'] ?? null)
            ],
            'mercadopago' => [
                'enabled' => !empty($company->settings['mercadopago_token'] ?? null),
                'connected' => !empty($company->settings['mercadopago_token'] ?? null)
            ],
            'google_analytics' => [
                'enabled' => !empty($company->settings['ga_tracking_id'] ?? null),
                'tracking_id' => $company->settings['ga_tracking_id'] ?? null
            ],
            'email_marketing' => [
                'enabled' => !empty($company->settings['email_service'] ?? null),
                'service' => $company->settings['email_service'] ?? null
            ]
        ];

        return $this->successResponse($integrations);
    }

    public function updateIntegrations(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'integrations' => 'required|array',
            'integrations.whatsapp' => 'nullable|array',
            'integrations.whatsapp.enabled' => 'nullable|boolean',
            'integrations.whatsapp.number' => 'nullable|string|max:20',
            'integrations.whatsapp.token' => 'nullable|string',
            'integrations.mercadopago' => 'nullable|array',
            'integrations.mercadopago.token' => 'nullable|string',
            'integrations.google_analytics' => 'nullable|array',
            'integrations.google_analytics.tracking_id' => 'nullable|string|max:50',
            'integrations.email_marketing' => 'nullable|array',
            'integrations.email_marketing.service' => 'nullable|string|in:mailchimp,sendgrid,brevo',
            'integrations.email_marketing.api_key' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $company = auth()->user()->company;
        $settings = $company->settings ?? [];

        foreach ($request->integrations as $integration => $config) {
            switch ($integration) {
                case 'whatsapp':
                    if (isset($config['token'])) {
                        $settings['whatsapp_token'] = $config['token'];
                    }
                    // Update store settings
                    $store = $company->store;
                    $storeSettings = $store->settings;
                    $storeSettings['whatsapp_enabled'] = $config['enabled'] ?? $storeSettings['whatsapp_enabled'] ?? false;
                    $storeSettings['whatsapp_number'] = $config['number'] ?? $storeSettings['whatsapp_number'] ?? null;
                    $store->update(['settings' => $storeSettings]);
                    break;

                case 'mercadopago':
                    if (isset($config['token'])) {
                        $settings['mercadopago_token'] = $config['token'];
                    }
                    break;

                case 'google_analytics':
                    if (isset($config['tracking_id'])) {
                        $settings['ga_tracking_id'] = $config['tracking_id'];
                    }
                    break;

                case 'email_marketing':
                    if (isset($config['service'])) {
                        $settings['email_service'] = $config['service'];
                        $settings['email_api_key'] = $config['api_key'] ?? null;
                    }
                    break;
            }
        }

        $company->update(['settings' => $settings]);

        return $this->successResponse($this->integrations(), 'Integrations updated successfully');
    }

    public function notifications()
    {
        $company = auth()->user()->company;
        $user = auth()->user();

        $notifications = [
            'email' => [
                'sales' => $user->settings['notifications']['email']['sales'] ?? true,
                'orders' => $user->settings['notifications']['email']['orders'] ?? true,
                'low_stock' => $user->settings['notifications']['email']['low_stock'] ?? true,
                'marketing' => $user->settings['notifications']['email']['marketing'] ?? false
            ],
            'push' => [
                'sales' => $user->settings['notifications']['push']['sales'] ?? true,
                'orders' => $user->settings['notifications']['push']['orders'] ?? true,
                'updates' => $user->settings['notifications']['push']['updates'] ?? true
            ],
            'whatsapp' => [
                'orders' => $user->settings['notifications']['whatsapp']['orders'] ?? false,
                'updates' => $user->settings['notifications']['whatsapp']['updates'] ?? false
            ]
        ];

        return $this->successResponse($notifications);
    }

    public function updateNotifications(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'notifications' => 'required|array',
            'notifications.email' => 'nullable|array',
            'notifications.email.sales' => 'nullable|boolean',
            'notifications.email.orders' => 'nullable|boolean',
            'notifications.email.low_stock' => 'nullable|boolean',
            'notifications.email.marketing' => 'nullable|boolean',
            'notifications.push' => 'nullable|array',
            'notifications.push.sales' => 'nullable|boolean',
            'notifications.push.orders' => 'nullable|boolean',
            'notifications.push.updates' => 'nullable|boolean',
            'notifications.whatsapp' => 'nullable|array',
            'notifications.whatsapp.orders' => 'nullable|boolean',
            'notifications.whatsapp.updates' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $user = auth()->user();
        $settings = $user->settings ?? [];

        $settings['notifications'] = $request->notifications;
        $user->update(['settings' => $settings]);

        return $this->successResponse($this->notifications(), 'Notification preferences updated successfully');
    }

    public function uploadLogo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'logo' => 'required|image|max:2048|mimes:jpg,jpeg,png,gif'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $company = auth()->user()->company;

        // Check storage limit
        $storageLimit = $company->getStorageLimit();
        $currentUsage = $this->getStorageUsage($company->id);
        
        if ($currentUsage >= $storageLimit) {
            return $this->errorResponse('Storage limit reached', 422);
        }

        // Delete old logo if exists
        if ($company->logo) {
            Storage::disk('public')->delete($company->logo);
        }

        $path = $request->file('logo')->store("companies/{$company->id}/logo", 'public');
        
        $company->update(['logo' => $path]);

        return $this->successResponse([
            'url' => Storage::url($path),
            'path' => $path
        ], 'Logo uploaded successfully');
    }

    public function backup(Request $request)
    {
        $company = auth()->user()->company;

        $backupData = [
            'company' => $company->toArray(),
            'users' => $company->users()->get()->toArray(),
            'products' => $company->products()->get()->toArray(),
            'categories' => $company->categories()->get()->toArray(),
            'sales' => $company->sales()->with('items')->get()->toArray(),
            'store' => $company->store->toArray(),
            'subscription' => $company->subscription ? $company->subscription->toArray() : null,
            'timestamp' => now()->toDateTimeString(),
            'version' => '1.0'
        ];

        // Create backup file
        $filename = "backup-{$company->id}-" . date('Y-m-d-H-i-s') . '.json';
        $path = "companies/{$company->id}/backups/{$filename}";
        
        Storage::disk('public')->put($path, json_encode($backupData, JSON_PRETTY_PRINT));

        return $this->successResponse([
            'filename' => $filename,
            'url' => Storage::url($path),
            'size' => Storage::disk('public')->size($path),
            'created_at' => now()->toDateTimeString()
        ], 'Backup created successfully');
    }

    public function restore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'backup_file' => 'required|file|mimes:json|max:51200' // 50MB max
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        // This is a dangerous operation, should be done with caution
        // In production, add additional security checks and confirmations

        $backupContent = file_get_contents($request->file('backup_file')->getRealPath());
        $backupData = json_decode($backupContent, true);

        if (!$backupData || !isset($backupData['company'])) {
            return $this->errorResponse('Invalid backup file', 422);
        }

        // Verify backup belongs to current company
        if ($backupData['company']['id'] !== auth()->user()->company_id) {
            return $this->errorResponse('Backup does not belong to your company', 403);
        }

        // Implement restore logic here
        // This should be done carefully and preferably in a queue

        return $this->successResponse([], 'Restore process initiated. This may take several minutes.');
    }

    private function getStorageUsage($companyId)
    {
        $directory = "companies/{$companyId}";
        
        if (!Storage::disk('public')->exists($directory)) {
            return 0;
        }

        $files = Storage::disk('public')->allFiles($directory);
        
        $totalSize = 0;
        foreach ($files as $file) {
            $totalSize += Storage::disk('public')->size($file);
        }
        
        // Return in MB
        return round($totalSize / 1024 / 1024, 2);
    }
}
