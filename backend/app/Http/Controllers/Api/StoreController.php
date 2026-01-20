<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Traits\ApiResponse;

class StoreController extends Controller
{
    use ApiResponse;

    public function show()
    {
        $company = auth()->user()->company;
        $store = $company->store()->firstOrCreate([]);

        return $this->successResponse($store);
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|alpha_dash|unique:stores,slug,' . auth()->user()->company->store->id,
            'description' => 'nullable|string',
            'logo' => 'nullable|string',
            'cover_image' => 'nullable|string',
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'whatsapp_enabled' => 'nullable|boolean',
            'whatsapp_number' => 'nullable|string|max:20',
            'instagram' => 'nullable|string|max:100',
            'facebook' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'business_hours' => 'nullable|array',
            'delivery_enabled' => 'nullable|boolean',
            'delivery_radius' => 'nullable|numeric|min:0',
            'delivery_fee' => 'nullable|numeric|min:0',
            'payment_methods' => 'nullable|array',
            'status' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $company = auth()->user()->company;
        $store = $company->store;

        $settings = array_merge($store->settings ?? [], [
            'primary_color' => $request->primary_color ?? '#4361ee',
            'whatsapp_enabled' => $request->whatsapp_enabled ?? true,
            'whatsapp_number' => $request->whatsapp_number,
            'instagram' => $request->instagram,
            'facebook' => $request->facebook,
            'business_hours' => $request->business_hours,
            'delivery_enabled' => $request->delivery_enabled ?? false,
            'delivery_radius' => $request->delivery_radius,
            'delivery_fee' => $request->delivery_fee,
            'payment_methods' => $request->payment_methods ?? ['cash', 'pix']
        ]);

        $store->update([
            'name' => $request->name,
            'slug' => Str::slug($request->slug),
            'description' => $request->description,
            'logo' => $request->logo,
            'cover_image' => $request->cover_image,
            'settings' => $settings,
            'status' => $request->has('status') ? $request->status : $store->status
        ]);

        return $this->successResponse($store, 'Store updated successfully');
    }

    public function stats()
    {
        $company = auth()->user()->company;
        $store = $company->store;

        $totalVisits = $store->visits()->count();
        $monthVisits = $store->visits()->whereMonth('created_at', now()->month)->count();
        $onlineOrders = $company->sales()->where('type', 'online')->count();
        $conversionRate = $totalVisits > 0 ? ($onlineOrders / $totalVisits) * 100 : 0;

        // Popular products
        $popularProducts = Product::where('company_id', $company->id)
            ->whereHas('saleItems', function ($query) {
                $query->whereHas('sale', function ($query) {
                    $query->where('type', 'online');
                });
            })
            ->withCount(['saleItems as total_sold' => function ($query) {
                $query->select(DB::raw('SUM(quantity)'))
                    ->whereHas('sale', function ($query) {
                        $query->where('type', 'online');
                    });
            }])
            ->orderBy('total_sold', 'desc')
            ->limit(5)
            ->get();

        return $this->successResponse([
            'visits' => [
                'total' => $totalVisits,
                'this_month' => $monthVisits,
                'trend' => $this->calculateTrend($store->visits())
            ],
            'orders' => [
                'total' => $onlineOrders,
                'conversion_rate' => round($conversionRate, 2)
            ],
            'popular_products' => $popularProducts,
            'store_status' => $store->status ? 'active' : 'inactive',
            'last_published' => $store->updated_at->format('Y-m-d H:i:s')
        ]);
    }

    public function orders(Request $request)
    {
        $company = auth()->user()->company;

        $orders = $company->sales()
            ->where('type', 'online')
            ->with(['items.product', 'cashier'])
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->status);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return $this->successResponse($orders);
    }

    public function updateOrderStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,processing,completed,cancelled'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $company = auth()->user()->company;
        $order = $company->sales()
            ->where('type', 'online')
            ->findOrFail($id);

        $order->update(['status' => $request->status]);

        // Send notification to customer if email exists
        if ($order->customer_email && in_array($request->status, ['processing', 'completed', 'cancelled'])) {
            // Send email notification
            // Mail::to($order->customer_email)->send(new OrderStatusUpdated($order));
        }

        return $this->successResponse($order->fresh(), 'Order status updated successfully');
    }

    public function publish()
    {
        $company = auth()->user()->company;
        $store = $company->store;

        // Check if store has required information
        if (!$store->name || !$store->slug) {
            return $this->errorResponse('Store name and slug are required', 422);
        }

        // Check if store has at least one active product
        $activeProducts = $company->products()->where('status', true)->count();
        if ($activeProducts === 0) {
            return $this->errorResponse('Add at least one active product before publishing', 422);
        }

        $store->update([
            'status' => true,
            'published_at' => now()
        ]);

        return $this->successResponse($store, 'Store published successfully');
    }

    // Public methods (no auth required)

    public function publicShow($slug)
    {
        $store = Store::where('slug', $slug)
            ->where('status', true)
            ->with('company')
            ->firstOrFail();

        // Record visit
        $store->visits()->create([
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);

        return $this->successResponse([
            'store' => $store,
            'company' => $store->company->only(['name', 'phone', 'address', 'city', 'state']),
            'settings' => $store->settings
        ]);
    }

    public function publicProducts($slug)
    {
        $store = Store::where('slug', $slug)
            ->where('status', true)
            ->firstOrFail();

        $products = Product::where('company_id', $store->company_id)
            ->where('status', true)
            ->with('category')
            ->orderBy('name')
            ->get();

        return $this->successResponse($products);
    }

    public function placeOrder(Request $request, $slug)
    {
        $validator = Validator::make($request->all(), [
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email|max:255',
            'customer_phone' => 'required|string|max:20',
            'customer_address' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
            'delivery_type' => 'required|in:pickup,delivery'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $store = Store::where('slug', $slug)
            ->where('status', true)
            ->firstOrFail();

        // Create sale record
        $sale = $store->company->sales()->create([
            'invoice_number' => $this->generateInvoiceNumber($store->company_id),
            'cashier_id' => null, // No cashier for online orders
            'customer_name' => $request->customer_name,
            'customer_email' => $request->customer_email,
            'customer_phone' => $request->customer_phone,
            'type' => 'online',
            'status' => 'pending',
            'subtotal' => 0, // Will be calculated
            'total_amount' => 0, // Will be calculated
            'amount_paid' => 0,
            'payment_method' => 'pending',
            'notes' => $request->notes,
            'metadata' => [
                'delivery_type' => $request->delivery_type,
                'customer_address' => $request->customer_address,
                'source' => 'online_store'
            ]
        ]);

        // Calculate totals and create items
        $subtotal = 0;
        
        foreach ($request->items as $item) {
            $product = Product::findOrFail($item['product_id']);
            
            // Verify product belongs to store's company
            if ($product->company_id !== $store->company_id) {
                continue;
            }

            $itemSubtotal = $item['quantity'] * $product->price;
            $subtotal += $itemSubtotal;

            $sale->items()->create([
                'product_id' => $product->id,
                'quantity' => $item['quantity'],
                'unit_price' => $product->price,
                'subtotal' => $itemSubtotal,
                'tax_amount' => $itemSubtotal * ($product->tax_rate / 100)
            ]);

            // Update product stock
            $product->updateStock($item['quantity'], 'sale');
        }

        // Add delivery fee if applicable
        $deliveryFee = 0;
        if ($request->delivery_type === 'delivery' && ($store->settings['delivery_enabled'] ?? false)) {
            $deliveryFee = $store->settings['delivery_fee'] ?? 0;
        }

        $total = $subtotal + $deliveryFee;

        $sale->update([
            'subtotal' => $subtotal,
            'total_amount' => $total,
            'metadata->delivery_fee' => $deliveryFee
        ]);

        // Send WhatsApp notification if enabled
        if ($store->settings['whatsapp_enabled'] ?? false) {
            $this->sendWhatsAppNotification($store, $sale);
        }

        // Send email confirmation
        // Mail::to($request->customer_email)->send(new OrderConfirmation($sale));

        return $this->successResponse([
            'order' => $sale,
            'order_number' => $sale->invoice_number,
            'message' => 'Order placed successfully. We will contact you soon.'
        ], 'Order placed successfully', 201);
    }

    public function validateSlug($slug)
    {
        $exists = Store::where('slug', $slug)->exists();
        
        return $this->successResponse([
            'available' => !$exists,
            'slug' => $slug
        ]);
    }

    private function calculateTrend($visitsQuery)
    {
        $currentMonth = $visitsQuery->whereMonth('created_at', now()->month)->count();
        $lastMonth = $visitsQuery->whereMonth('created_at', now()->subMonth()->month)->count();

        if ($lastMonth === 0) {
            return $currentMonth > 0 ? 100 : 0;
        }

        return round((($currentMonth - $lastMonth) / $lastMonth) * 100, 2);
    }

    private function sendWhatsAppNotification($store, $sale)
    {
        $phone = $store->settings['whatsapp_number'] ?? $store->company->phone;
        
        if (!$phone) {
            return;
        }

        $message = "📦 *NOVO PEDIDO ONLINE* 📦\n";
        $message .= "Pedido #{$sale->invoice_number}\n";
        $message .= "Cliente: {$sale->customer_name}\n";
        $message .= "Telefone: {$sale->customer_phone}\n";
        $message .= "Total: R$ " . number_format($sale->total_amount, 2, ',', '.') . "\n";
        $message .= "Itens: " . $sale->items->count() . "\n";
        $message .= "Acesse o painel para mais detalhes.";

        // In production, use a WhatsApp API service
        // Example with Twilio:
        // $client = new Twilio\Rest\Client($sid, $token);
        // $client->messages->create(
        //     "whatsapp:+55" . preg_replace('/\D/', '', $phone),
        //     ['from' => 'whatsapp:+14155238886', 'body' => $message]
        // );
    }

    private function generateInvoiceNumber($companyId)
    {
        $prefix = 'ONL';
        $date = date('Ymd');
        $sequence = Sale::where('company_id', $companyId)
            ->where('type', 'online')
            ->whereDate('created_at', today())
            ->count() + 1;

        return $prefix . '-' . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
}
