<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('external_id', 64);
            $table->string('order_number', 64)->nullable();
            $table->uuid('customer_id')->nullable();
            $table->string('external_member_id', 64)->nullable();
            // As received. Never used to create an identity for marketplace orders.
            $table->string('email', 320)->nullable();
            $table->string('channel', 16);
            $table->string('source', 64)->nullable();
            $table->boolean('is_marketplace')->default(false);
            $table->integer('status_code')->nullable();
            $table->string('status_label', 128)->nullable();
            $table->boolean('counts_as_revenue')->default(true);
            $table->timestamp('placed_at');
            $table->timestamp('source_updated_at')->nullable();
            $table->char('currency', 3)->nullable();
            $table->decimal('net_total', 14, 4)->default(0);
            $table->decimal('grand_total', 14, 4)->default(0);
            $table->decimal('discount_total', 14, 4)->default(0);
            $table->decimal('shipping_total', 14, 4)->default(0);
            $table->string('coupon_code', 128)->nullable();
            $table->decimal('coupon_amount', 14, 4)->default(0);
            $table->jsonb('attributes')->default('{}');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->nullOnDelete();
            $table->unique(['tenant_id', 'external_id']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'customer_id', 'placed_at']);
            $table->index(['tenant_id', 'placed_at']);
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_channel_check CHECK (channel IN ('web', 'mobile_app', 'marketplace', 'other'))");

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_id');
            $table->string('external_id', 64);
            $table->string('product_external_id', 64)->nullable();
            $table->string('variant_external_id', 64)->nullable();
            $table->uuid('product_id')->nullable();
            $table->uuid('variant_id')->nullable();
            $table->string('sku', 128)->nullable();
            $table->string('barcode', 128)->nullable();
            $table->string('name')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_list_price', 14, 4)->nullable();
            $table->decimal('unit_price', 14, 4)->nullable();
            $table->decimal('tax_amount', 14, 4)->default(0);
            $table->decimal('discount_amount', 14, 4)->default(0);
            $table->decimal('row_total', 14, 4)->default(0);
            $table->integer('status_code')->nullable();
            $table->string('status_label', 128)->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('orders')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->nullOnDelete();
            $table->foreign(['tenant_id', 'variant_id'])->references(['tenant_id', 'id'])->on('product_variants')->nullOnDelete();
            $table->unique(['tenant_id', 'external_id']);
            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'sku']);
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('external_id', 64);
            $table->uuid('customer_id')->nullable();
            $table->string('external_member_id', 64)->nullable();
            $table->string('email', 320)->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('total', 14, 4)->default(0);
            $table->string('content_hash', 64);
            $table->timestamp('source_created_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->nullOnDelete();
            $table->unique(['tenant_id', 'external_id']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'source_created_at']);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('cart_id');
            $table->string('external_id', 64);
            $table->string('product_external_id', 64)->nullable();
            $table->string('variant_external_id', 64)->nullable();
            $table->uuid('product_id')->nullable();
            $table->uuid('variant_id')->nullable();
            $table->string('sku', 128)->nullable();
            $table->string('name')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 4)->nullable();
            $table->decimal('unit_price_with_tax', 14, 4)->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'cart_id'])->references(['tenant_id', 'id'])->on('carts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->nullOnDelete();
            $table->foreign(['tenant_id', 'variant_id'])->references(['tenant_id', 'id'])->on('product_variants')->nullOnDelete();
            $table->unique(['tenant_id', 'external_id']);
            $table->index(['tenant_id', 'cart_id']);
        });

        foreach (['orders', 'order_items', 'carts', 'cart_items'] as $table) {
            Rls::isolateTenantTable($table);
            Rls::grantApp($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
