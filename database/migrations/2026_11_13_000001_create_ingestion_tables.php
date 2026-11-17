<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_sources', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->string('token_prefix', 12);
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['branch_id', 'name']);
        });
        Schema::create('ingestion_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('reference')->unique();
            $t->foreignId('api_source_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $t->json('payload');
            $t->string('fingerprint', 64);
            $t->string('status')->default('queued')->index();
            $t->string('error')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->index(['branch_id', 'created_at']);
            $t->index(['api_source_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_events');
        Schema::dropIfExists('api_sources');
    }
};
