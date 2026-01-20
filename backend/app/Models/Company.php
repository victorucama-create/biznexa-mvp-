<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'legal_name',
        'tax_id',
        'email',
        'phone',
        'website',
        'logo',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
        'timezone',
        'currency',
        'language',
        'status',
        'plan_id',
        'subscription_ends_at',
        'settings'
    ];

    protected $casts = [
        'settings' => 'array',
        'subscription_ends_at' => 'datetime',
        'status' => 'boolean'
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function store()
    {
        return $this->hasOne(Store::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class)->latest();
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function isActive()
    {
        return $this->status && $this->subscription_ends_at > now();
    }

    public function getStorageLimit()
    {
        return $this->plan->storage_limit ?? 100; // MB
    }

    public function getUserLimit()
    {
        return $this->plan->user_limit ?? 1;
    }

    public function canAddUser()
    {
        return $this->users()->count() < $this->getUserLimit();
    }
}
