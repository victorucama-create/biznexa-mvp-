<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'slug',
        'description',
        'logo',
        'cover_image',
        'settings',
        'status',
        'published_at'
    ];

    protected $casts = [
        'settings' => 'array',
        'status' => 'boolean',
        'published_at' => 'datetime'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function visits()
    {
        return $this->hasMany(StoreVisit::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function getUrlAttribute()
    {
        return config('app.url') . '/store/' . $this->slug;
    }

    public function getWhatsappUrlAttribute()
    {
        $number = $this->settings['whatsapp_number'] ?? $this->company->phone;
        if (!$number) return null;

        $cleanNumber = preg_replace('/\D/', '', $number);
        $message = "Olá! Gostaria de mais informações sobre os produtos da {$this->name}.";

        return "https://wa.me/55{$cleanNumber}?text=" . urlencode($message);
    }

    public function incrementVisit()
    {
        $this->visits()->create([
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'visited_at' => now()
        ]);
    }
}
