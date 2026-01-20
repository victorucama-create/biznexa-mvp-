<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Store;
use App\Models\BusinessHighlight;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Traits\ApiResponse;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class MarketController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $company = auth()->user()->company;

        // Get all businesses (excluding current user's business)
        $businesses = QueryBuilder::for(Company::class)
            ->where('id', '!=', $company->id)
            ->where('status', true)
            ->whereHas('store', function ($query) {
                $query->where('status', true);
            })
            ->with(['store', 'plan'])
            ->allowedFilters([
                'name',
                'city',
                'state',
                AllowedFilter::scope('category'),
                AllowedFilter::scope('has_highlight')
            ])
            ->allowedSorts(['name', 'city', 'created_at'])
            ->paginate($request->get('per_page', 12));

        return $this->successResponse($businesses);
    }

    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:2|max:100',
            'category' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string|max:2'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $company = auth()->user()->company;

        $businesses = Company::where('id', '!=', $company->id)
            ->where('status', true)
            ->whereHas('store', function ($query) {
                $query->where('status', true);
            })
            ->where(function ($query) use ($request) {
                $query->where('name', 'like', "%{$request->query}%")
                    ->orWhereHas('store', function ($q) use ($request) {
                        $q->where('name', 'like', "%{$request->query}%")
                          ->orWhere('description', 'like', "%{$request->query}%");
                    });
            })
            ->when($request->filled('category'), function ($query) use ($request) {
                // Assuming categories are stored in company settings or separate table
                $query->whereJsonContains('settings->categories', $request->category);
            })
            ->when($request->filled('city'), function ($query) use ($request) {
                $query->where('city', 'like', "%{$request->city}%");
            })
            ->when($request->filled('state'), function ($query) use ($request) {
                $query->where('state', $request->state);
            })
            ->with(['store', 'plan'])
            ->orderBy('name')
            ->paginate($request->get('per_page', 12));

        return $this->successResponse([
            'businesses' => $businesses,
            'search_query' => $request->query,
            'total_results' => $businesses->total()
        ]);
    }

    public function show($id)
    {
        $company = auth()->user()->company;
        
        $business = Company::where('id', '!=', $company->id)
            ->where('status', true)
            ->whereHas('store', function ($query) {
                $query->where('status', true);
            })
            ->with(['store', 'plan'])
            ->findOrFail($id);

        // Get business stats
        $stats = [
            'store_visits' => $business->store->visits()->count(),
            'products_count' => $business->products()->where('status', true)->count(),
            'rating' => $this->calculateRating($business->id),
            'joined_date' => $business->created_at->format('M Y')
        ];

        // Check if current user's company has highlighted this business
        $hasHighlighted = BusinessHighlight::where('company_id', $company->id)
            ->where('highlighted_company_id', $id)
            ->where('status', 'active')
            ->exists();

        return $this->successResponse([
            'business' => $business,
            'stats' => $stats,
            'has_highlighted' => $hasHighlighted,
            'contact_allowed' => true // Based on business settings
        ]);
    }

    public function addHighlight(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:companies,id',
            'highlight_type' => 'required|in:basic,premium,featured',
            'duration_days' => 'required|integer|min:1|max:365'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $company = auth()->user()->company;
        $targetCompany = Company::findOrFail($request->business_id);

        // Check if company can add highlights (based on plan)
        if (!$company->plan->hasFeature('can_highlight')) {
            return $this->errorResponse('Your plan does not include business highlighting', 403);
        }

        // Check if already highlighted
        $existingHighlight = BusinessHighlight::where('company_id', $company->id)
            ->where('highlighted_company_id', $targetCompany->id)
            ->where('status', 'active')
            ->first();

        if ($existingHighlight) {
            return $this->errorResponse('You have already highlighted this business', 422);
        }

        // Calculate cost based on highlight type and duration
        $cost = $this->calculateHighlightCost(
            $request->highlight_type,
            $request->duration_days,
            $company->plan->code
        );

        // Check company balance or process payment
        if (!$this->processPayment($company, $cost)) {
            return $this->errorResponse('Payment failed. Please check your balance.', 422);
        }

        $highlight = BusinessHighlight::create([
            'company_id' => $company->id,
            'highlighted_company_id' => $targetCompany->id,
            'highlight_type' => $request->highlight_type,
            'duration_days' => $request->duration_days,
            'cost' => $cost,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addDays($request->duration_days)
        ]);

        // Update business visibility score
        $this->updateVisibilityScore($targetCompany->id);

        return $this->successResponse($highlight, 'Business highlighted successfully');
    }

    // Public methods (no auth required)

    public function publicIndex(Request $request)
    {
        $businesses = QueryBuilder::for(Company::class)
            ->where('status', true)
            ->whereHas('store', function ($query) {
                $query->where('status', true);
            })
            ->with(['store' => function ($query) {
                $query->select(['id', 'company_id', 'name', 'slug', 'logo', 'description']);
            }])
            ->allowedFilters([
                'city',
                'state',
                AllowedFilter::scope('category')
            ])
            ->allowedSorts(['name', 'city', 'created_at'])
            ->defaultSort('-has_highlight') // Show highlighted businesses first
            ->paginate($request->get('per_page', 12));

        return $this->successResponse($businesses);
    }

    public function publicSearch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'q' => 'required|string|min:2|max:100',
            'category' => 'nullable|string',
            'location' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $businesses = Company::where('status', true)
            ->whereHas('store', function ($query) {
                $query->where('status', true);
            })
            ->where(function ($query) use ($request) {
                $query->where('name', 'like', "%{$request->q}%")
                    ->orWhere('city', 'like', "%{$request->q}%")
                    ->orWhereHas('store', function ($q) use ($request) {
                        $q->where('name', 'like', "%{$request->q}%")
                          ->orWhere('description', 'like', "%{$request->q}%");
                    });
            })
            ->when($request->filled('category'), function ($query) use ($request) {
                $query->whereJsonContains('settings->categories', $request->category);
            })
            ->when($request->filled('location'), function ($query) use ($request) {
                $query->where('city', 'like', "%{$request->location}%")
                    ->orWhere('state', 'like', "%{$request->location}%");
            })
            ->with(['store' => function ($query) {
                $query->select(['id', 'company_id', 'name', 'slug', 'logo', 'description']);
            }])
            ->orderBy('name')
            ->paginate($request->get('per_page', 12));

        return $this->successResponse([
            'businesses' => $businesses,
            'search_query' => $request->q,
            'total_results' => $businesses->total()
        ]);
    }

    public function publicShow($id)
    {
        $business = Company::where('status', true)
            ->whereHas('store', function ($query) {
                $query->where('status', true);
            })
            ->with(['store'])
            ->findOrFail($id);

        // Record view
        $business->store->visits()->create([
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'source' => 'market_directory'
        ]);

        // Get business products (limited to 10)
        $products = $business->products()
            ->where('status', true)
            ->with('category')
            ->limit(10)
            ->get();

        // Calculate rating
        $rating = $this->calculateRating($business->id);

        return $this->successResponse([
            'business' => $business,
            'store' => $business->store,
            'products' => $products,
            'stats' => [
                'rating' => $rating,
                'total_products' => $business->products()->where('status', true)->count(),
                'member_since' => $business->created_at->format('M Y')
            ],
            'contact_info' => [
                'phone' => $business->phone,
                'email' => $business->email,
                'website' => $business->website,
                'address' => $this->formatAddress($business)
            ]
        ]);
    }

    public function featuredBusinesses()
    {
        // Get businesses with active highlights
        $featured = Company::where('status', true)
            ->whereHas('store', function ($query) {
                $query->where('status', true);
            })
            ->whereHas('highlights', function ($query) {
                $query->where('status', 'active')
                    ->where('expires_at', '>', now())
                    ->where('highlight_type', 'featured');
            })
            ->with(['store' => function ($query) {
                $query->select(['id', 'company_id', 'name', 'slug', 'logo', 'description']);
            }])
            ->inRandomOrder()
            ->limit(6)
            ->get();

        return $this->successResponse($featured);
    }

    public function categories()
    {
        // Get all unique categories from businesses
        $categories = Company::where('status', true)
            ->whereNotNull('settings->categories')
            ->get()
            ->flatMap(function ($company) {
                return $company->settings['categories'] ?? [];
            })
            ->unique()
            ->values()
            ->toArray();

        return $this->successResponse($categories);
    }

    private function calculateRating($companyId)
    {
        // In a real app, this would calculate based on reviews
        // For now, return a random rating between 3.5 and 5
        return round(rand(35, 50) / 10, 1);
    }

    private function calculateHighlightCost($type, $days, $planCode)
    {
        $basePrices = [
            'basic' => 9.90,
            'premium' => 29.90,
            'featured' => 99.90
        ];

        $discount = 1.0;
        if ($planCode === 'pro') {
            $discount = 0.9; // 10% discount
        } elseif ($planCode === 'business') {
            $discount = 0.8; // 20% discount
        }

        $dailyRate = $basePrices[$type] ?? $basePrices['basic'];
        $total = $dailyRate * $days * $discount;

        return round($total, 2);
    }

    private function processPayment($company, $amount)
    {
        // In a real app, process payment via Stripe, Mercado Pago, etc.
        // For MVP, just check if company has enough balance
        $balance = $company->settings['balance'] ?? 0;
        
        if ($balance >= $amount) {
            // Deduct from balance
            $settings = $company->settings;
            $settings['balance'] = $balance - $amount;
            $company->update(['settings' => $settings]);
            
            // Record transaction
            $company->transactions()->create([
                'type' => 'highlight_purchase',
                'amount' => $amount,
                'description' => 'Business highlighting',
                'status' => 'completed'
            ]);
            
            return true;
        }
        
        return false;
    }

    private function updateVisibilityScore($companyId)
    {
        $company = Company::find($companyId);
        if (!$company) return;

        $highlightCount = BusinessHighlight::where('highlighted_company_id', $companyId)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->count();

        $storeVisits = $company->store->visits()->count();
        $productCount = $company->products()->where('status', true)->count();

        // Simple scoring algorithm
        $score = ($highlightCount * 10) + ($storeVisits * 0.1) + ($productCount * 0.5);
        
        $settings = $company->settings;
        $settings['visibility_score'] = $score;
        $company->update(['settings' => $settings]);
    }

    private function formatAddress($company)
    {
        $parts = [];
        if ($company->address) $parts[] = $company->address;
        if ($company->city) $parts[] = $company->city;
        if ($company->state) $parts[] = $company->state;
        
        return implode(', ', $parts);
    }
}
