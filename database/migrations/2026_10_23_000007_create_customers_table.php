<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal canonical customer table. F1 (commerce sync) extends it; F0 only
 * needs it to prove tenant isolation on personal data. The platform role
 * gets no grant on this table at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'email']);
        });

        Rls::isolateTenantTable('customers');
        Rls::grantApp('customers');
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
