<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('external_id', 64);
            $table->string('parent_external_id', 64)->nullable();
            $table->uuid('parent_id')->nullable();
            $table->string('name');
            $table->string('code', 64)->nullable();
            $table->string('url', 1024)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'external_id']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'parent_id'])->references(['tenant_id', 'id'])->on('categories')->nullOnDelete();
            $table->index(['tenant_id', 'parent_external_id']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('external_id', 64);
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('brand_external_id', 64)->nullable();
            $table->string('main_category_external_id', 64)->nullable();
            $table->string('url', 1024)->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('total_stock', 14, 3)->default(0);
            $table->timestamp('source_created_at')->nullable();
            $table->jsonb('attributes')->default('{}');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'external_id']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->string('external_id', 64);
            $table->string('sku', 128)->nullable();
            $table->string('barcode', 128)->nullable();
            $table->decimal('price', 14, 4)->nullable();
            $table->decimal('sale_price', 14, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->decimal('stock', 14, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->jsonb('options')->default('[]');
            $table->timestamp('stock_updated_at')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->unique(['tenant_id', 'external_id']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'sku']);
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->string('category_external_id', 64);
            $table->uuid('category_id')->nullable();

            $table->primary(['tenant_id', 'product_id', 'category_external_id']);
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('categories')->nullOnDelete();
            $table->index(['tenant_id', 'category_external_id']);
        });

        foreach (['categories', 'products', 'product_variants', 'product_categories'] as $table) {
            Rls::isolateTenantTable($table);
            Rls::grantApp($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
