<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Traits\ApiResponse;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class ProductController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $company = auth()->user()->company;

        $products = QueryBuilder::for(Product::class)
            ->where('company_id', $company->id)
            ->allowedFilters([
                'name',
                'sku',
                'barcode',
                'status',
                AllowedFilter::exact('category_id'),
                AllowedFilter::scope('low_stock'),
                AllowedFilter::scope('search')
            ])
            ->allowedSorts(['name', 'price', 'stock', 'created_at'])
            ->with('category')
            ->paginate($request->get('per_page', 15));

        return $this->successResponse($products);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sku' => 'nullable|string|max:50|unique:products,sku',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'barcode' => 'nullable|string|max:100|unique:products,barcode',
            'images' => 'nullable|array',
            'images.*' => 'nullable|string',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'unit' => 'nullable|string|max:20',
            'weight' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $company = auth()->user()->company;

        // Generate SKU if not provided
        $sku = $request->sku ?? $this->generateSku($company->id);

        $product = $company->products()->create([
            'sku' => $sku,
            'name' => $request->name,
            'description' => $request->description,
            'category_id' => $request->category_id,
            'price' => $request->price,
            'cost' => $request->cost ?? $request->price * 0.6, // Default cost = 60% of price
            'stock' => $request->stock,
            'min_stock' => $request->min_stock ?? 5,
            'max_stock' => $request->max_stock,
            'barcode' => $request->barcode ?? $this->generateBarcode(),
            'images' => $this->processImages($request->images),
            'status' => true,
            'tax_rate' => $request->tax_rate ?? 0,
            'unit' => $request->unit ?? 'un',
            'weight' => $request->weight,
            'dimensions' => $request->dimensions
        ]);

        return $this->successResponse($product->load('category'), 'Product created successfully', 201);
    }

    public function show($id)
    {
        $company = auth()->user()->company;
        
        $product = $company->products()
            ->with(['category', 'saleItems'])
            ->findOrFail($id);

        return $this->successResponse($product);
    }

    public function update(Request $request, $id)
    {
        $company = auth()->user()->company;
        $product = $company->products()->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'sku' => 'nullable|string|max:50|unique:products,sku,' . $product->id,
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'barcode' => 'nullable|string|max:100|unique:products,barcode,' . $product->id,
            'images' => 'nullable|array',
            'images.*' => 'nullable|string',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'unit' => 'nullable|string|max:20',
            'weight' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|array',
            'status' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $product->update([
            'sku' => $request->sku ?? $product->sku,
            'name' => $request->name,
            'description' => $request->description,
            'category_id' => $request->category_id,
            'price' => $request->price,
            'cost' => $request->cost,
            'stock' => $request->stock,
            'min_stock' => $request->min_stock,
            'max_stock' => $request->max_stock,
            'barcode' => $request->barcode ?? $product->barcode,
            'images' => $this->processImages($request->images, $product->images),
            'status' => $request->has('status') ? $request->status : $product->status,
            'tax_rate' => $request->tax_rate,
            'unit' => $request->unit,
            'weight' => $request->weight,
            'dimensions' => $request->dimensions
        ]);

        return $this->successResponse($product->fresh()->load('category'), 'Product updated successfully');
    }

    public function destroy($id)
    {
        $company = auth()->user()->company;
        $product = $company->products()->findOrFail($id);

        // Check if product has sales
        if ($product->saleItems()->exists()) {
            return $this->errorResponse('Cannot delete product with sales history', 422);
        }

        $product->delete();

        return $this->successResponse([], 'Product deleted successfully');
    }

    public function bulkUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'products' => 'required|array',
            'products.*.id' => 'required|exists:products,id',
            'action' => 'required|in:update_price,update_stock,activate,deactivate'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $company = auth()->user()->company;
        $updatedCount = 0;

        foreach ($request->products as $productData) {
            $product = $company->products()->find($productData['id']);
            
            if ($product) {
                switch ($request->action) {
                    case 'update_price':
                        if (isset($productData['price'])) {
                            $product->update(['price' => $productData['price']]);
                            $updatedCount++;
                        }
                        break;
                    
                    case 'update_stock':
                        if (isset($productData['stock'])) {
                            $product->update(['stock' => $productData['stock']]);
                            $updatedCount++;
                        }
                        break;
                    
                    case 'activate':
                        $product->update(['status' => true]);
                        $updatedCount++;
                        break;
                    
                    case 'deactivate':
                        $product->update(['status' => false]);
                        $updatedCount++;
                        break;
                }
            }
        }

        return $this->successResponse([
            'updated_count' => $updatedCount
        ], "{$updatedCount} products updated successfully");
    }

    public function export(Request $request)
    {
        $company = auth()->user()->company;
        
        $products = $company->products()
            ->with('category')
            ->get()
            ->map(function ($product) {
                return [
                    'SKU' => $product->sku,
                    'Nome' => $product->name,
                    'Categoria' => $product->category->name,
                    'Preço' => $product->price,
                    'Custo' => $product->cost,
                    'Estoque' => $product->stock,
                    'Estoque Mínimo' => $product->min_stock,
                    'Código de Barras' => $product->barcode,
                    'Status' => $product->status ? 'Ativo' : 'Inativo',
                    'Data de Criação' => $product->created_at->format('d/m/Y H:i')
                ];
            });

        return $this->successResponse($products, 'Products exported successfully');
    }

    public function lowStock()
    {
        $company = auth()->user()->company;
        
        $products = $company->products()
            ->lowStock()
            ->with('category')
            ->paginate(10);

        return $this->successResponse($products);
    }

    private function generateSku($companyId)
    {
        $prefix = 'PROD';
        $date = date('Ymd');
        $sequence = Product::where('company_id', $companyId)->count() + 1;
        
        return $prefix . '-' . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    private function generateBarcode()
    {
        return 'BAR' . time() . rand(1000, 9999);
    }

    private function processImages($newImages, $existingImages = [])
    {
        if (!$newImages) {
            return $existingImages;
        }

        // Keep existing images if not in new images
        $images = array_intersect($existingImages, $newImages);
        
        // Add new images
        foreach ($newImages as $image) {
            if (!in_array($image, $images)) {
                $images[] = $image;
            }
        }

        return array_values(array_unique($images));
    }

    public function uploadImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|max:2048|mimes:jpg,jpeg,png,gif'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $company = auth()->user()->company;
        
        // Check storage limit
        $storageLimit = $company->getStorageLimit();
        $currentUsage = $this->getStorageUsage($company->id);
        
        if ($currentUsage >= $storageLimit * 1024 * 1024) { // Convert MB to bytes
            return $this->errorResponse('Storage limit reached', 422);
        }

        $path = $request->file('image')->store("companies/{$company->id}/products", 'public');

        return $this->successResponse([
            'url' => Storage::url($path),
            'path' => $path
        ], 'Image uploaded successfully');
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
        
        return $totalSize;
    }
}
