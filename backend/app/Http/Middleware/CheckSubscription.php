<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();
        
        if (!$user || !$user->company) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 401);
        }
        
        $company = $user->company;
        
        // Check if company is active
        if (!$company->status) {
            return response()->json([
                'message' => 'Your company account is disabled'
            ], 403);
        }
        
        // Check subscription status
        if (!$company->isActive()) {
            // Allow access to billing and logout routes only
            $allowedRoutes = [
                'api.billing.*',
                'api.auth.logout',
                'api.auth.me'
            ];
            
            $routeName = $request->route()->getName();
            $isAllowed = false;
            
            foreach ($allowedRoutes as $allowedRoute) {
                if (str_is($allowedRoute, $routeName)) {
                    $isAllowed = true;
                    break;
                }
            }
            
            if (!$isAllowed) {
                return response()->json([
                    'message' => 'Your subscription has expired. Please renew to continue using the service.',
                    'subscription_ends_at' => $company->subscription_ends_at
                ], 403);
            }
        }
        
        return $next($request);
    }
}
