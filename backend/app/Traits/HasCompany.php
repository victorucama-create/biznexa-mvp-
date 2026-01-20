<?php

namespace App\Traits;

use App\Models\Company;

trait HasCompany
{
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeForCompany($query, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;
        
        return $query->where('company_id', $companyId);
    }

    public function scopeForCurrentCompany($query)
    {
        return $this->scopeForCompany($query, auth()->user()->company_id);
    }

    protected static function bootHasCompany()
    {
        static::creating(function ($model) {
            if (auth()->check() && !$model->company_id) {
                $model->company_id = auth()->user()->company_id;
            }
        });
    }
}
