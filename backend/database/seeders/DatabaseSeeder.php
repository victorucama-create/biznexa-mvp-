<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create plans
        $plans = [
            [
                'name' => 'Starter',
                'code' => 'starter',
                'description' => 'Ideal para pequenos negócios',
                'price_monthly' => 29.90,
                'price_yearly' => 299.00,
                'user_limit' => 1,
                'product_limit' => 100,
                'storage_limit' => 100, // MB
                'features' => ['basic_store', 'basic_reports'],
                'is_active' => true
            ],
            [
                'name' => 'Pro',
                'code' => 'pro',
                'description' => 'Para negócios em crescimento',
                'price_monthly' => 89.90,
                'price_yearly' => 899.00,
                'user_limit' => 3,
                'product_limit' => 500,
                'storage_limit' => 500,
                'features' => ['online_store', 'advanced_reports', 'basic_highlight'],
                'is_active' => true
            ],
            [
                'name' => 'Business',
                'code' => 'business',
                'description' => 'Solução completa para PMEs',
                'price_monthly' => 199.90,
                'price_yearly' => 1999.00,
                'user_limit' => null, // unlimited
                'product_limit' => null, // unlimited
                'storage_limit' => 2000,
                'features' => ['premium_store', 'advanced_analytics', 'premium_highlight', 'ads'],
                'is_active' => true
            ]
        ];

        foreach ($plans as $planData) {
            Plan::create($planData);
        }

        // Create demo company
        $company = Company::create([
            'name' => 'Demo Store',
            'legal_name' => 'Demo Store LTDA',
            'email' => 'demo@biznexa.com',
            'phone' => '+5511999999999',
            'tax_id' => '12.345.678/0001-99',
            'address' => 'Rua das Flores, 123',
            'city' => 'São Paulo',
            'state' => 'SP',
            'country' => 'Brasil',
            'postal_code' => '01234-567',
            'currency' => 'BRL',
            'language' => 'pt_BR',
            'status' => true,
            'plan_id' => Plan::where('code', 'pro')->first()->id,
            'subscription_ends_at' => now()->addYear()
        ]);

        // Create demo store
        $company->store()->create([
            'name' => 'Demo Store',
            'slug' => 'demo-store',
            'description' => 'Loja de demonstração da Biznexa',
            'settings' => [
                'theme' => 'default',
                'primary_color' => '#4361ee',
                'whatsapp_enabled' => true,
                'whatsapp_number' => '+5511999999999',
                'instagram' => '@demostore',
                'facebook' => 'demostore'
            ],
            'status' => true
        ]);

        // Create demo user
        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Admin Demo',
            'email' => 'admin@demo.com',
            'password' => Hash::make('password123'),
            'phone' => '+5511999999999',
            'status' => true
        ]);

        // Create roles and permissions
        $this->call(RolePermissionSeeder::class);
        $user->assignRole('admin');

        // Create categories
        $categories = [
            ['name' => 'Eletrônicos', 'slug' => 'eletronicos'],
            ['name' => 'Roupas', 'slug' => 'roupas'],
            ['name' => 'Alimentos', 'slug' => 'alimentos'],
            ['name' => 'Casa', 'slug' => 'casa'],
            ['name' => 'Beleza', 'slug' => 'beleza']
        ];

        foreach ($categories as $categoryData) {
            Category::create(array_merge($categoryData, ['company_id' => $company->id]));
        }

        // Create demo products
        $products = [
            [
                'sku' => 'PROD-20240119-0001',
                'name' => 'Smartphone XYZ',
                'description' => 'Smartphone de última geração',
                'category_id' => Category::where('name', 'Eletrônicos')->first()->id,
                'price' => 1299.99,
                'cost' => 899.99,
                'stock' => 25,
                'min_stock' => 5,
                'barcode' => '7891234567890',
                'images' => ['https://via.placeholder.com/400x400/4361ee/ffffff?text=Smartphone'],
                'tax_rate' => 18.0,
                'unit' => 'un',
                'weight' => 0.5
            ],
            [
                'sku' => 'PROD-20240119-0002',
                'name' => 'Camiseta Básica',
                'description' => 'Camiseta de algodão 100%',
                'category_id' => Category::where('name', 'Roupas')->first()->id,
                'price' => 49.90,
                'cost' => 29.90,
                'stock' => 100,
                'min_stock' => 20,
                'barcode' => '7891234567891',
                'images' => ['https://via.placeholder.com/400x400/7209b7/ffffff?text=Camiseta'],
                'tax_rate' => 12.0,
                'unit' => 'un',
                'weight' => 0.3
            ]
        ];

        foreach ($products as $productData) {
            Product::create(array_merge($productData, ['company_id' => $company->id]));
        }

        // Create demo sales
        $this->createDemoSales($company, $user);

        $this->command->info('Demo data created successfully!');
        $this->command->info('Admin Login: admin@demo.com');
        $this->command->info('Password: password123');
    }

    private function createDemoSales($company, $user)
    {
        $sales = [
            [
                'invoice_number' => 'INV-' . date('Ymd') . '-0001',
                'cashier_id' => $user->id,
                'customer_name' => 'João Silva',
                'customer_email' => 'joao@email.com',
                'type' => 'store',
                'subtotal' => 1349.89,
                'tax_amount' => 242.98,
                'total_amount' => 1592.87,
                'amount_paid' => 1600.00,
                'change_amount' => 7.13,
                'payment_method' => 'cash',
                'notes' => 'Venda de demonstração'
            ]
        ];

        foreach ($sales as $saleData) {
            $sale = $company->sales()->create($saleData);
            
            // Add items
            $products = Product::where('company_id', $company->id)->get();
            
            foreach ($products as $product) {
                $quantity = rand(1, 3);
                $sale->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'subtotal' => $product->price * $quantity
                ]);
                
                // Update product stock
                $product->updateStock($quantity, 'sale');
            }
        }
    }
}
