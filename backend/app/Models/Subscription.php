<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'plan_id',
        'billing_cycle',
        'price',
        'status',
        'starts_at',
        'next_billing_date',
        'cancelled_at',
        'auto_renew',
        'payment_method',
        'cancellation_reason',
        'metadata'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'starts_at' => 'datetime',
        'next_billing_date' => 'datetime',
        'cancelled_at' => 'datetime',
        'auto_renew' => 'boolean',
        'metadata' => 'array'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeExpired($query)
    {
        return $query->where('next_billing_date', '<', now())
            ->where('auto_renew', true)
            ->where('status', 'active');
    }

    public function getIsActiveAttribute()
    {
        return $this->status === 'active' && $this->next_billing_date > now();
    }

    public function getDaysUntilBillingAttribute()
    {
        return now()->diffInDays($this->next_billing_date, false);
    }

    public function renew()
    {
        $this->update([
            'next_billing_date' => $this->billing_cycle === 'yearly'
                ? $this->next_billing_date->addYear()
                : $this->next_billing_date->addMonth()
        ]);

        // Create new invoice
        $this->invoices()->create([
            'invoice_number' => $this->generateInvoiceNumber(),
            'amount' => $this->price,
            'total_amount' => $this->price,
            'status' => 'pending',
            'due_date' => $this->next_billing_date,
            'items' => [
                [
                    'description' => "Renovação do plano {$this->plan->name} ({$this->billing_cycle})",
                    'amount' => $this->price
                ]
            ]
        ]);
    }

    private function generateInvoiceNumber()
    {
        $prefix = 'INV';
        $date = date('Ymd');
        $sequence = Invoice::whereDate('created_at', today())->count() + 1;

        return $prefix . '-' . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
}
