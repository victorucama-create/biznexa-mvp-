<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use App\Traits\ApiResponse;

class SaleController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $company = auth()->user()->company;
        
        $sales = $company->sales()
            ->with(['items.product', 'cashier'])
            ->when($request->filled('start_date'), function ($query) use ($request) {
                $query->whereDate('created_at', '>=', $request->start_date);
            })
            ->when($request->filled('end_date'), function ($query) use ($request) {
                $query->whereDate('created_at', '<=', $request->end_date);
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->status);
            })
            ->when($request->filled('type'), function ($query) use ($request) {
                $query->where('type', $request->type);
            })
            ->when($request->filled('payment_method'), function ($query) use ($request) {
                $query->where('payment_method', $request->payment_method);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return $this->successResponse($sales);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'customer_tax_id' => 'nullable|string|max:50',
            'type' => 'required|in:store,online,delivery',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'payment_method' => 'required|in:cash,credit_card,debit_card,transfer,pix',
            'amount_paid' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        DB::beginTransaction();

        try {
            $company = auth()->user()->company;
            $user = auth()->user();

            // Generate invoice number
            $invoiceNumber = $this->generateInvoiceNumber($company->id);

            // Calculate totals
            $subtotal = 0;
            $taxAmount = 0;
            $items = [];

            foreach ($request->items as $item) {
                $product = Product::where('company_id', $company->id)
                    ->findOrFail($item['product_id']);

                // Check stock
                if ($product->stock < $item['quantity']) {
                    throw new \Exception("Insufficient stock for product: {$product->name}. Available: {$product->stock}");
                }

                $itemSubtotal = $item['quantity'] * $item['unit_price'];
                $itemTax = $itemSubtotal * ($product->tax_rate / 100);
                
                $subtotal += $itemSubtotal;
                $taxAmount += $itemTax;

                $items[] = [
                    'product' => $product,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'subtotal' => $itemSubtotal,
                    'tax_amount' => $itemTax
                ];

                // Update product stock
                $product->updateStock($item['quantity'], 'sale');
            }

            $discountAmount = $request->discount_amount ?? 0;
            $totalAmount = $subtotal + $taxAmount - $discountAmount;
            $amountPaid = $request->amount_paid;
            $changeAmount = max(0, $amountPaid - $totalAmount);

            // Create sale
            $sale = $company->sales()->create([
                'invoice_number' => $invoiceNumber,
                'cashier_id' => $user->id,
                'customer_name' => $request->customer_name,
                'customer_email' => $request->customer_email,
                'customer_phone' => $request->customer_phone,
                'customer_tax_id' => $request->customer_tax_id,
                'type' => $request->type,
                'status' => 'completed',
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                'change_amount' => $changeAmount,
                'payment_method' => $request->payment_method,
                'notes' => $request->notes,
                'metadata' => $request->metadata
            ]);

            // Create sale items
            foreach ($items as $item) {
                $sale->items()->create([
                    'product_id' => $item['product']->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'subtotal' => $item['subtotal'],
                    'tax_amount' => $item['tax_amount']
                ]);
            }

            DB::commit();

            return $this->successResponse(
                $sale->load(['items.product', 'cashier']),
                'Sale completed successfully',
                201
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Sale failed: ' . $e->getMessage(), 500);
        }
    }

    public function quickSale(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'payment_method' => 'required|in:cash,credit_card,debit_card,transfer,pix',
            'amount_paid' => 'required|numeric|min:0'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $request->merge([
            'type' => 'store',
            'customer_name' => 'Cliente Avulso'
        ]);

        return $this->store($request);
    }

    public function show($id)
    {
        $company = auth()->user()->company;
        
        $sale = $company->sales()
            ->with(['items.product', 'cashier'])
            ->findOrFail($id);

        return $this->successResponse($sale);
    }

    public function update(Request $request, $id)
    {
        $company = auth()->user()->company;
        $sale = $company->sales()->findOrFail($id);

        // Only allow updating certain fields
        $validator = Validator::make($request->all(), [
            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'customer_tax_id' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:pending,completed,cancelled'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $sale->update($request->only([
            'customer_name', 'customer_email', 'customer_phone',
            'customer_tax_id', 'notes', 'status'
        ]));

        return $this->successResponse($sale->fresh()->load(['items.product', 'cashier']), 'Sale updated successfully');
    }

    public function destroy($id)
    {
        $company = auth()->user()->company;
        $sale = $company->sales()->findOrFail($id);

        // Return stock
        foreach ($sale->items as $item) {
            $product = $item->product;
            $product->updateStock($item->quantity, 'purchase');
        }

        $sale->delete();

        return $this->successResponse([], 'Sale deleted successfully');
    }

    public function cancel($id)
    {
        $company = auth()->user()->company;
        $sale = $company->sales()->findOrFail($id);

        if ($sale->status === 'cancelled') {
            return $this->errorResponse('Sale is already cancelled', 422);
        }

        DB::beginTransaction();

        try {
            // Return stock
            foreach ($sale->items as $item) {
                $product = $item->product;
                $product->updateStock($item->quantity, 'purchase');
            }

            $sale->update(['status' => 'cancelled']);

            DB::commit();

            return $this->successResponse($sale->fresh(), 'Sale cancelled successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to cancel sale: ' . $e->getMessage(), 500);
        }
    }

    public function todaySales()
    {
        $company = auth()->user()->company;
        $today = Carbon::today();

        $sales = $company->sales()
            ->with(['items.product', 'cashier'])
            ->whereDate('created_at', $today)
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->get();

        $total = $sales->sum('total_amount');
        $count = $sales->count();
        $average = $count > 0 ? $total / $count : 0;

        return $this->successResponse([
            'sales' => $sales,
            'summary' => [
                'total_amount' => $total,
                'sales_count' => $count,
                'average_ticket' => round($average, 2),
                'date' => $today->format('Y-m-d')
            ]
        ]);
    }

    public function printReceipt($id)
    {
        $company = auth()->user()->company;
        $sale = $company->sales()
            ->with(['items.product', 'cashier', 'company'])
            ->findOrFail($id);

        // Generate receipt data
        $receipt = [
            'company' => [
                'name' => $company->name,
                'address' => $company->address,
                'phone' => $company->phone,
                'tax_id' => $company->tax_id
            ],
            'sale' => $sale,
            'items' => $sale->items,
            'cashier' => $sale->cashier->name,
            'date' => $sale->created_at->format('d/m/Y H:i:s'),
            'receipt_number' => $sale->invoice_number
        ];

        return $this->successResponse($receipt, 'Receipt generated successfully');
    }

    private function generateInvoiceNumber($companyId)
    {
        $prefix = 'INV';
        $date = date('Ymd');
        $sequence = Sale::where('company_id', $companyId)
            ->whereDate('created_at', today())
            ->count() + 1;

        return $prefix . '-' . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
}
