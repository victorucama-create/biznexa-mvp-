<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreVisit extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'ip_address',
        'user_agent',
        'source',
        'visited_at'
    ];

    protected $casts = [
        'visited_at' => 'datetime'
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('visited_at', today());
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('visited_at', now()->month);
    }
}
