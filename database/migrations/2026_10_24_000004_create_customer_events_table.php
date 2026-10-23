<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-independent, versioned event envelope. (tenant, source,
 * source_event_id) is unique, so re-reading the same source data never
 * produces a second event. Events derived from historical data during a
 * full sync carry is_backfill = true and must not trigger automations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('customer_id')->nullable();
            $table->string('name', 64);
            $table->unsignedSmallInteger('schema_version');
            $table->timestamp('occurred_at');
            $table->timestamp('received_at')->useCurrent();
            $table->string('source', 32);
            $table->string('source_event_id', 191);
            $table->boolean('is_backfill')->default(false);
            $table->jsonb('properties')->default('{}');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->nullOnDelete();
            $table->unique(['tenant_id', 'source', 'source_event_id']);
            $table->index(['tenant_id', 'name', 'occurred_at']);
            $table->index(['tenant_id', 'customer_id', 'occurred_at']);
        });

        Rls::isolateTenantTable('customer_events');
        Rls::grantApp('customer_events', ['SELECT', 'INSERT']);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_events');
    }
};
