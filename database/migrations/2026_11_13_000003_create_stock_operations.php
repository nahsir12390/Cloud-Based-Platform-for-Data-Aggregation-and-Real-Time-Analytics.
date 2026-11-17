<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_operations', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->string('kind', 20);
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('destination_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->integer('quantity');
            $t->unsignedInteger('expected_quantity')->nullable();
            $t->unsignedInteger('resulting_quantity')->nullable();
            $t->string('reason', 40);
            $t->string('note', 200);
            $t->string('fingerprint', 64);
            $t->timestamps();
            $t->index(['kind', 'created_at']);
            $t->index(['branch_id', 'created_at']);
            $t->index(['destination_branch_id', 'created_at']);
        });
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->foreignId('stock_operation_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('stock_operation_id');
        });
        Schema::dropIfExists('stock_operations');
    }
};
