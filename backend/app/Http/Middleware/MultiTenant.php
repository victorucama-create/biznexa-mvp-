<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MultiTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check()) {
            $companyId = auth()->user()->company_id;
            
            // Set global company_id scope
            config(['company_id' => $companyId]);
            
            // Add company_id to request
            $request->merge(['company_id' => $companyId]);
            
            // Apply company scope to all relevant models
            $this->applyCompanyScope();
        }

        return $next($request);
    }

    private function applyCompanyScope()
    {
        $companyId = config('company_id');
        
        // Apply scope to Product model
        \App\Models\Product::addGlobalScope('company', function ($query) use ($companyId) {
            $query->where('company_id', $companyId);
        });
        
        // Apply scope to Sale model
        \App\Models\Sale::addGlobalScope('company', function ($query) use ($companyId) {
            $query->where('company_id', $companyId);
        });
        
        // Apply scope to Category model
        \App\Models\Category::addGlobalScope('company', function ($query) use ($companyId) {
            $query->where('company_id', $companyId);
        });
        
        // Apply scope to Store model
        \App\Models\Store::addGlobalScope('company', function ($query) use ($companyId) {
            $query->where('company_id', $companyId);
        });
    }
}
