<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'sku',
        'name',
        'description',
        'category_id',
        'price',
        'cost',
        'stock',
        'min_stock',
        'max_stock',
        'barcode',
        'images',
        'status',
        'tax_rate',
        'unit',
        'weight',
        'dimensions'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost' => 'decimal:2',
        'stock' => 'integer',
        'min_stock' => 'integer',
        'max_stock' => 'integer',
        'images' => 'array',
        'status' => 'boolean',
        'tax_rate' => 'decimal:2',
        'weight' => 'decimal:2',
        'dimensions' => 'array'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function scopeLowStock($query)
    {
        return $query->whereRaw('stock <= min_stock')
                    ->where('min_stock', '>', 0);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
    }

    public function getStockStatusAttribute()
    {
        if ($this->stock <= 0) {
            return 'out_of_stock';
        } elseif ($this->min_stock > 0 && $this->stock <= $this->min_stock) {
            return 'low_stock';
        } else {
            return 'in_stock';
        }
    }

    public function getFormattedPriceAttribute()
    {
        return number_format($this->price, 2, ',', '.');
    }

    public function updateStock($quantity, $type = 'sale')
    {
        if ($type === 'sale') {
            $this->stock -= $quantity;
        } elseif ($type === 'purchase') {
            $this->stock += $quantity;
        } elseif ($type === 'adjustment') {
            $this->stock = $quantity;
        }
        
        $this->save();
    }
}
