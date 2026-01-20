<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('sku')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories');
            $table->decimal('price', 10, 2);
            $table->decimal('cost', 10, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->integer('min_stock')->default(0);
            $table->integer('max_stock')->nullable();
            $table->string('barcode')->nullable();
            $table->json('images')->nullable();
            $table->boolean('status')->default(true);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('unit')->default('un');
            $table->decimal('weight', 10, 2)->nullable();
            $table->json('dimensions')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['company_id', 'sku', 'deleted_at']);
            $table->unique(['company_id', 'barcode', 'deleted_at']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
