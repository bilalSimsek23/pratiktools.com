<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical customer identity. customers.id (UUIDv7) is the only canonical
 * id; every external or contact identifier lives in customer_identities and
 * is matched deterministically. Ticimax permission flags are stored as
 * source data only (source_consent) and are not a consent decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
            $table->date('birth_date')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->timestamp('member_since')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->boolean('is_active')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            // Ticimax MailIzin / SmsIzin / permission dates, newsletter flags.
            // Source data only; the consent engine (F2) decides.
            $table->jsonb('source_consent')->default('{}');
            $table->jsonb('attributes')->default('{}');
        });

        Schema::create('customer_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('customer_id');
            $table->string('type', 32);
            $table->string('value', 320);
            $table->string('source', 32);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->cascadeOnDelete();
            $table->unique(['tenant_id', 'type', 'value']);
            $table->index(['tenant_id', 'customer_id']);
        });
        DB::statement("ALTER TABLE customer_identities ADD CONSTRAINT customer_identities_type_check CHECK (type IN ('commerce_member', 'email', 'phone', 'newsletter_subscriber'))");

        Schema::create('customer_stats', function (Blueprint $table) {
            $table->uuid('customer_id')->primary();
            $table->uuid('tenant_id');
            $table->unsignedInteger('order_count')->default(0);
            $table->decimal('lifetime_value', 14, 2)->default(0);
            $table->decimal('average_order_value', 14, 2)->default(0);
            $table->char('currency', 3)->nullable();
            $table->timestamp('first_order_at')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->timestamp('last_cart_at')->nullable();
            $table->timestamp('computed_at')->useCurrent();

            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->cascadeOnDelete();
            $table->index(['tenant_id', 'last_order_at']);
        });

        // Deterministic conflicts that need a human decision. No automatic merge.
        Schema::create('customer_merges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('primary_customer_id');
            $table->uuid('secondary_customer_id')->nullable();
            $table->string('identity_type', 32);
            $table->string('identity_hash', 64);
            $table->string('status', 16)->default('pending_review');
            $table->string('reason', 120);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'primary_customer_id'])->references(['tenant_id', 'id'])->on('customers')->cascadeOnDelete();
            $table->unique(['tenant_id', 'identity_type', 'identity_hash', 'secondary_customer_id']);
        });

        foreach (['customer_identities', 'customer_stats', 'customer_merges'] as $table) {
            Rls::isolateTenantTable($table);
            Rls::grantApp($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_merges');
        Schema::dropIfExists('customer_stats');
        Schema::dropIfExists('customer_identities');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['phone', 'birth_date', 'gender', 'city', 'district', 'member_since', 'is_guest', 'is_active', 'source_updated_at', 'source_consent', 'attributes']);
        });
    }
};
