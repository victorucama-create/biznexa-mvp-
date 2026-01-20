<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'company_id',
        'cashier_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_tax_id',
        'type',
        'status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'amount_paid',
        'change_amount',
        'payment_method',
        'notes',
        'metadata'
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'change_amount' => 'decimal:2',
        'metadata' => 'array'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeOnline($query)
    {
        return $query->where('type', 'online');
    }

    public function scopeStore($query)
    {
        return $query->where('type', 'store');
    }

    public function getFormattedTotalAttribute()
    {
        return 'R$ ' . number_format($this->total_amount, 2, ',', '.');
    }

    public function getPaymentMethodTextAttribute()
    {
        $methods = [
            'cash' => 'Dinheiro',
            'credit_card' => 'Cartão de Crédito',
            'debit_card' => 'Cartão de Débito',
            'transfer' => 'Transferência',
            'pix' => 'PIX'
        ];

        return $methods[$this->payment_method] ?? $this->payment_method;
    }

    public function getStatusTextAttribute()
    {
        $statuses = [
            'pending' => 'Pendente',
            'completed' => 'Concluída',
            'cancelled' => 'Cancelada',
            'processing' => 'Processando'
        ];

        return $statuses[$this->status] ?? $this->status;
    }

    public function getItemsCountAttribute()
    {
        return $this->items->sum('quantity');
    }

    public function updateStatus($status, $notes = null)
    {
        $this->update([
            'status' => $status,
            'notes' => $notes ? $this->notes . "\n" . $notes : $this->notes
        ]);

        // If cancelling, return stock
        if ($status === 'cancelled') {
            foreach ($this->items as $item) {
                $product = $item->product;
                $product->updateStock($item->quantity, 'purchase');
            }
        }
    }
}
