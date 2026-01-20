<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'price_monthly',
        'price_yearly',
        'user_limit',
        'product_limit',
        'storage_limit',
        'features',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'price_monthly' => 'decimal:2',
        'price_yearly' => 'decimal:2',
        'user_limit' => 'integer',
        'product_limit' => 'integer',
        'storage_limit' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];

    public function companies()
    {
        return $this->hasMany(Company::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('price_monthly');
    }

    public function getYearlyDiscountAttribute()
    {
        if ($this->price_yearly > 0 && $this->price_monthly > 0) {
            $yearlyFromMonthly = $this->price_monthly * 12;
            $discount = $yearlyFromMonthly - $this->price_yearly;
            $percentage = ($discount / $yearlyFromMonthly) * 100;
            
            return round($percentage, 2);
        }
        
        return 0;
    }

    public function getFormattedPriceMonthlyAttribute()
    {
        return 'R$ ' . number_format($this->price_monthly, 2, ',', '.');
    }

    public function getFormattedPriceYearlyAttribute()
    {
        return 'R$ ' . number_format($this->price_yearly, 2, ',', '.');
    }

    public function hasFeature($feature)
    {
        return in_array($feature, $this->features ?? []);
    }

    public function getFeaturesListAttribute()
    {
        $featuresMap = [
            'basic_store' => 'Loja básica',
            'online_store' => 'Loja online completa',
            'premium_store' => 'Loja premium',
            'basic_reports' => 'Relatórios básicos',
            'advanced_reports' => 'Relatórios avançados',
            'advanced_analytics' => 'Análises avançadas',
            'basic_highlight' => 'Destaque básico no diretório',
            'premium_highlight' => 'Destaque premium no diretório',
            'ads' => 'Anúncios pagos',
            'api_access' => 'Acesso à API',
            'priority_support' => 'Suporte prioritário',
            'custom_domain' => 'Domínio personalizado',
            'multi_store' => 'Múltiplas lojas',
            'inventory_management' => 'Gestão de estoque',
            'sales_analytics' => 'Análise de vendas',
            'customer_management' => 'Gestão de clientes',
            'team_collaboration' => 'Colaboração em equipe',
            'automated_backups' => 'Backups automáticos',
            'advanced_security' => 'Segurança avançada'
        ];

        $list = [];
        foreach ($this->features ?? [] as $feature) {
            if (isset($featuresMap[$feature])) {
                $list[] = $featuresMap[$feature];
            } else {
                $list[] = $feature;
            }
        }

        return $list;
    }
}
