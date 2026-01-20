<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Traits\ApiResponse;

class BillingController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $company = auth()->user()->company;
        
        $subscription = $company->subscription()->with('plan')->first();
        $invoices = $company->invoices()->latest()->limit(5)->get();

        $usage = [
            'users' => [
                'current' => $company->users()->count(),
                'limit' => $company->getUserLimit(),
                'percentage' => $company->getUserLimit() > 0 
                    ? round(($company->users()->count() / $company->getUserLimit()) * 100, 2)
                    : 100
            ],
            'products' => [
                'current' => $company->products()->count(),
                'limit' => $company->plan->product_limit,
                'percentage' => $company->plan->product_limit > 0
                    ? round(($company->products()->count() / $company->plan->product_limit) * 100, 2)
                    : 100
            ],
            'storage' => [
                'current' => $this->getStorageUsage($company->id), // in MB
                'limit' => $company->getStorageLimit(),
                'percentage' => $company->getStorageLimit() > 0
                    ? round(($this->getStorageUsage($company->id) / $company->getStorageLimit()) * 100, 2)
                    : 100
            ]
        ];

        $nextBillingDate = $subscription ? $subscription->next_billing_date : null;
        $daysUntilBilling = $nextBillingDate ? Carbon::parse($nextBillingDate)->diffInDays(now()) : null;

        return $this->successResponse([
            'subscription' => $subscription,
            'plan' => $company->plan,
            'company_status' => [
                'is_active' => $company->isActive(),
                'status' => $company->status ? 'active' : 'suspended',
                'subscription_ends_at' => $company->subscription_ends_at,
                'days_remaining' => $company->subscription_ends_at ? Carbon::parse($company->subscription_ends_at)->diffInDays(now()) : null
            ],
            'usage' => $usage,
            'recent_invoices' => $invoices,
            'billing_info' => [
                'next_billing_date' => $nextBillingDate,
                'days_until_billing' => $daysUntilBilling,
                'auto_renew' => $subscription ? $subscription->auto_renew : true
            ]
        ]);
    }

    public function plans()
    {
        $plans = Plan::where('is_active', true)->get();
        $currentPlan = auth()->user()->company->plan;

        return $this->successResponse([
            'plans' => $plans,
            'current_plan' => $currentPlan
        ]);
    }

    public function subscribe(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|exists:plans,id',
            'billing_cycle' => 'required|in:monthly,yearly',
            'payment_method' => 'required|in:credit_card,pix,boleto',
            'coupon_code' => 'nullable|string|max:50'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $company = auth()->user()->company;
        $plan = Plan::findOrFail($request->plan_id);

        // Check if already subscribed to this plan
        if ($company->plan_id === $plan->id) {
            return $this->errorResponse('You are already subscribed to this plan', 422);
        }

        DB::beginTransaction();

        try {
            // Calculate price
            $price = $request->billing_cycle === 'yearly' 
                ? $plan->price_yearly 
                : $plan->price_monthly;

            // Apply coupon if provided
            $discount = 0;
            $finalPrice = $price;
            
            if ($request->filled('coupon_code')) {
                $coupon = $this->validateCoupon($request->coupon_code, $plan->id);
                if ($coupon) {
                    $discount = $coupon['discount_amount'];
                    $finalPrice = max(0, $price - $discount);
                }
            }

            // Process payment (simulated for MVP)
            $paymentResult = $this->processPayment($company, $finalPrice, $request->payment_method);
            
            if (!$paymentResult['success']) {
                throw new \Exception('Payment failed: ' . $paymentResult['message']);
            }

            // Update company plan
            $oldPlan = $company->plan;
            $company->update([
                'plan_id' => $plan->id,
                'subscription_ends_at' => now()->addMonth() // Extend subscription
            ]);

            // Create or update subscription
            $subscription = $company->subscription()->updateOrCreate(
                ['company_id' => $company->id],
                [
                    'plan_id' => $plan->id,
                    'billing_cycle' => $request->billing_cycle,
                    'price' => $finalPrice,
                    'status' => 'active',
                    'starts_at' => now(),
                    'next_billing_date' => $request->billing_cycle === 'yearly' 
                        ? now()->addYear() 
                        : now()->addMonth(),
                    'auto_renew' => true,
                    'payment_method' => $request->payment_method,
                    'metadata' => [
                        'previous_plan' => $oldPlan->name,
                        'coupon_code' => $request->coupon_code,
                        'discount_amount' => $discount,
                        'original_price' => $price,
                        'payment_id' => $paymentResult['payment_id']
                    ]
                ]
            );

            // Create invoice
            $invoice = $company->invoices()->create([
                'subscription_id' => $subscription->id,
                'invoice_number' => $this->generateInvoiceNumber(),
                'amount' => $finalPrice,
                'tax_amount' => 0,
                'total_amount' => $finalPrice,
                'currency' => 'BRL',
                'status' => 'paid',
                'due_date' => now(),
                'paid_at' => now(),
                'items' => [
                    [
                        'description' => "Plano {$plan->name} ({$request->billing_cycle})",
                        'amount' => $price
                    ]
                ],
                'metadata' => [
                    'plan_name' => $plan->name,
                    'billing_cycle' => $request->billing_cycle,
                    'discount' => $discount
                ]
            ]);

            // Send confirmation email
            // Mail::to($company->email)->send(new SubscriptionConfirmation($subscription, $invoice));

            DB::commit();

            return $this->successResponse([
                'subscription' => $subscription,
                'invoice' => $invoice,
                'plan' => $plan,
                'message' => 'Subscription activated successfully'
            ], 'Subscription activated successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Subscription failed: ' . $e->getMessage(), 500);
        }
    }

    public function upgrade(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|exists:plans,id',
            'prorate' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $company = auth()->user()->company;
        $newPlan = Plan::findOrFail($request->plan_id);
        $currentPlan = $company->plan;

        // Check if it's actually an upgrade
        if ($newPlan->price_monthly <= $currentPlan->price_monthly) {
            return $this->errorResponse('This is not an upgrade. Use downgrade instead.', 422);
        }

        // Check current subscription
        $subscription = $company->subscription;
        if (!$subscription) {
            return $this->errorResponse('No active subscription found', 422);
        }

        // Calculate prorated amount if requested
        $additionalAmount = 0;
        if ($request->prorate) {
            $additionalAmount = $this->calculateProratedAmount(
                $currentPlan->price_monthly,
                $newPlan->price_monthly,
                $subscription->next_billing_date
            );
        }

        // Process payment for additional amount
        if ($additionalAmount > 0) {
            $paymentResult = $this->processPayment($company, $additionalAmount, $subscription->payment_method);
            if (!$paymentResult['success']) {
                return $this->errorResponse('Payment failed: ' . $paymentResult['message'], 422);
            }
        }

        // Update subscription
        $subscription->update([
            'plan_id' => $newPlan->id,
            'price' => $newPlan->price_monthly,
            'metadata->upgraded_from' => $currentPlan->id,
            'metadata->upgrade_date' => now(),
            'metadata->prorated_amount' => $additionalAmount
        ]);

        // Update company
        $company->update(['plan_id' => $newPlan->id]);

        // Create upgrade invoice if there was an additional charge
        if ($additionalAmount > 0) {
            $company->invoices()->create([
                'subscription_id' => $subscription->id,
                'invoice_number' => $this->generateInvoiceNumber(),
                'amount' => $additionalAmount,
                'total_amount' => $additionalAmount,
                'status' => 'paid',
                'due_date' => now(),
                'paid_at' => now(),
                'items' => [
                    [
                        'description' => "Upgrade de {$currentPlan->name} para {$newPlan->name} (prorated)",
                        'amount' => $additionalAmount
                    ]
                ]
            ]);
        }

        return $this->successResponse([
            'subscription' => $subscription->fresh(),
            'new_plan' => $newPlan,
            'additional_amount' => $additionalAmount,
            'message' => 'Upgrade completed successfully'
        ], 'Upgrade completed successfully');
    }

    public function downgrade(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|exists:plans,id'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $company = auth()->user()->company;
        $newPlan = Plan::findOrFail($request->plan_id);
        $currentPlan = $company->plan;

        // Check if it's actually a downgrade
        if ($newPlan->price_monthly >= $currentPlan->price_monthly) {
            return $this->errorResponse('This is not a downgrade. Use upgrade instead.', 422);
        }

        // Check if downgrade is allowed
        if (!$this->canDowngrade($company, $newPlan)) {
            return $this->errorResponse('Cannot downgrade due to usage limits', 422);
        }

        // Update subscription for next billing cycle
        $subscription = $company->subscription;
        if ($subscription) {
            $subscription->update([
                'plan_id' => $newPlan->id,
                'price' => $newPlan->price_monthly,
                'metadata->downgraded_from' => $currentPlan->id,
                'metadata->downgrade_date' => now(),
                'metadata->downgrade_effective_next_cycle' => true
            ]);
        }

        // Schedule plan change for next billing cycle
        $company->update([
            'plan_id' => $newPlan->id,
            'settings->pending_downgrade' => [
                'from_plan' => $currentPlan->id,
                'to_plan' => $newPlan->id,
                'effective_date' => $subscription->next_billing_date
            ]
        ]);

        return $this->successResponse([
            'message' => 'Downgrade scheduled for next billing cycle',
            'effective_date' => $subscription->next_billing_date,
            'new_plan' => $newPlan,
            'note' => 'Your current features will remain active until the next billing cycle.'
        ], 'Downgrade scheduled successfully');
    }

    public function cancel(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500',
            'feedback' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $company = auth()->user()->company;
        $subscription = $company->subscription;

        if (!$subscription) {
            return $this->errorResponse('No active subscription found', 422);
        }

        // Update subscription
        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $request->reason,
            'auto_renew' => false,
            'metadata->cancellation_feedback' => $request->feedback
        ]);

        // Schedule company deactivation
        $company->update([
            'subscription_ends_at' => $subscription->next_billing_date,
            'settings->subscription_cancelled' => true
        ]);

        // Send cancellation confirmation
        // Mail::to($company->email)->send(new SubscriptionCancelled($subscription));

        return $this->successResponse([
            'message' => 'Subscription cancellation scheduled',
            'cancellation_date' => now(),
            'active_until' => $subscription->next_billing_date,
            'note' => 'Your subscription will remain active until ' . $subscription->next_billing_date->format('d/m/Y')
        ], 'Subscription cancellation scheduled successfully');
    }

    public function invoices(Request $request)
    {
        $company = auth()->user()->company;
        
        $invoices = $company->invoices()
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->status);
            })
            ->when($request->filled('start_date'), function ($query) use ($request) {
                $query->whereDate('created_at', '>=', $request->start_date);
            })
            ->when($request->filled('end_date'), function ($query) use ($request) {
                $query->whereDate('created_at', '<=', $request->end_date);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 10));

        return $this->successResponse($invoices);
    }

    public function downloadInvoice($id)
    {
        $company = auth()->user()->company;
        $invoice = $company->invoices()->findOrFail($id);

        // Generate PDF invoice (simulated)
        $invoiceData = [
            'invoice' => $invoice,
            'company' => $company,
            'subscription' => $invoice->subscription,
            'plan' => $invoice->subscription->plan
        ];

        // In production, use a PDF library like Dompdf or TCPDF
        // $pdf = PDF::loadView('invoices.template', $invoiceData);
        // return $pdf->download("invoice-{$invoice->invoice_number}.pdf");

        return $this->successResponse($invoiceData, 'Invoice data retrieved. PDF generation would happen here.');
    }

    public function webhook($gateway, Request $request)
    {
        // Handle payment gateway webhooks (Stripe, Mercado Pago, etc.)
        switch ($gateway) {
            case 'stripe':
                return $this->handleStripeWebhook($request);
            case 'mercadopago':
                return $this->handleMercadoPagoWebhook($request);
            case 'asaas':
                return $this->handleAsaasWebhook($request);
            default:
                return response()->json(['error' => 'Unknown gateway'], 400);
        }
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
        
        // Convert to MB
        return round($totalSize / 1024 / 1024, 2);
    }

    private function validateCoupon($code, $planId)
    {
        // In production, validate against database
        $coupons = [
            'WELCOME10' => ['discount_percent' => 10, 'valid_plans' => ['starter', 'pro']],
            'LAUNCH20' => ['discount_percent' => 20, 'valid_plans' => ['pro', 'business']],
            'UPGRADE15' => ['discount_percent' => 15, 'valid_plans' => ['business']]
        ];

        if (!isset($coupons[$code])) {
            return null;
        }

        $coupon = $coupons[$code];
        $plan = Plan::find($planId);

        if (!in_array($plan->code, $coupon['valid_plans'])) {
            return null;
        }

        return [
            'code' => $code,
            'discount_percent' => $coupon['discount_percent'],
            'discount_amount' => 0 // Will be calculated based on price
        ];
    }

    private function processPayment($company, $amount, $method)
    {
        // Simulated payment processing
        // In production, integrate with payment gateway
        
        if ($amount <= 0) {
            return [
                'success' => true,
                'payment_id' => 'FREE-' . uniqid(),
                'message' => 'No payment required'
            ];
        }

        // Simulate payment failure for amounts over 1000
        if ($amount > 1000 && rand(1, 10) === 1) {
            return [
                'success' => false,
                'message' => 'Payment declined. Please try a different payment method.'
            ];
        }

        $paymentId = strtoupper($method) . '-' . time() . '-' . rand(1000, 9999);

        // Record payment
        $company->payments()->create([
            'payment_id' => $paymentId,
            'amount' => $amount,
            'currency' => 'BRL',
            'method' => $method,
            'status' => 'completed',
            'metadata' => [
                'processed_at' => now(),
                'simulated' => true
            ]
        ]);

        return [
            'success' => true,
            'payment_id' => $paymentId,
            'message' => 'Payment processed successfully'
        ];
    }

    private function calculateProratedAmount($oldPrice, $newPrice, $nextBillingDate)
    {
        $daysRemaining = Carbon::parse($nextBillingDate)->diffInDays(now());
        $daysInCycle = 30; // Assuming monthly cycle
        
        $dailyOldRate = $oldPrice / $daysInCycle;
        $dailyNewRate = $newPrice / $daysInCycle;
        
        $difference = $dailyNewRate - $dailyOldRate;
        
        return round($difference * $daysRemaining, 2);
    }

    private function canDowngrade($company, $newPlan)
    {
        // Check user limit
        if ($newPlan->user_limit !== null && $company->users()->count() > $newPlan->user_limit) {
            return false;
        }

        // Check product limit
        if ($newPlan->product_limit !== null && $company->products()->count() > $newPlan->product_limit) {
            return false;
        }

        // Check storage usage
        $storageUsage = $this->getStorageUsage($company->id);
        if ($newPlan->storage_limit !== null && $storageUsage > $newPlan->storage_limit) {
            return false;
        }

        return true;
    }

    private function generateInvoiceNumber()
    {
        $prefix = 'INV';
        $date = date('Ymd');
        $sequence = Invoice::whereDate('created_at', today())->count() + 1;

        return $prefix . '-' . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    private function handleStripeWebhook(Request $request)
    {
        // Implement Stripe webhook handling
        return response()->json(['received' => true]);
    }

    private function handleMercadoPagoWebhook(Request $request)
    {
        // Implement Mercado Pago webhook handling
        return response()->json(['received' => true]);
    }

    private function handleAsaasWebhook(Request $request)
    {
        // Implement Asaas webhook handling
        return response()->json(['received' => true]);
    }
}
