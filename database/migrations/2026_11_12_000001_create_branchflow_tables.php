<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('location');
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('staff');
            $t->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('sku')->unique();
            $t->string('category');
            $t->unsignedBigInteger('price_minor');
            $t->unsignedInteger('reorder_level')->default(10);
            $t->timestamps();
        });
        Schema::create('stocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity')->default(0);
            $t->unique(['branch_id', 'product_id']);
            $t->timestamps();
        });
        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_price_minor');
            $t->unsignedBigInteger('total_minor');
            $t->string('source')->default('web');
            $t->string('status')->default('completed');
            $t->timestamp('sold_at')->index();
            $t->timestamps();
            $t->index(['branch_id', 'sold_at']);
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('sale_id')->nullable()->constrained();
            $t->integer('quantity');
            $t->string('type');
            $t->string('note');
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('branch_id')->nullable()->constrained();
            $t->string('action');
            $t->string('description');
            $t->timestamps();
        });
        Schema::create('imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('branch_id')->constrained();
            $t->string('filename');
            $t->string('path');
            $t->string('status')->default('queued');
            $t->unsignedInteger('processed')->default(0);
            $t->unsignedInteger('rejected')->default(0);
            $t->json('errors')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['imports', 'audit_logs', 'stock_movements', 'sales', 'stocks', 'products'] as $table) {
            Schema::dropIfExists($table);
        }Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('branch_id');
            $t->dropColumn('role');
        });
        Schema::dropIfExists('branches');
    }
};
