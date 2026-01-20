<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->string('logo')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable()->default('Brasil');
            $table->string('postal_code')->nullable();
            $table->string('timezone')->nullable()->default('America/Sao_Paulo');
            $table->string('currency')->nullable()->default('BRL');
            $table->string('language')->nullable()->default('pt_BR');
            $table->boolean('status')->default(true);
            $table->foreignId('plan_id')->nullable()->constrained('plans');
            $table->timestamp('subscription_ends_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['status', 'subscription_ends_at']);
            $table->unique(['email', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
