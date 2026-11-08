<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * merchant_verified consent strategy (IGS, 2026-11-08): the tenant has
 * operationally verified that its store (Ticimax) e-mail permissions are
 * valid commercial message consents. Not an İYS or ViA verification.
 *
 * - tenant_settings.consent_strategy accepts 'merchant_verified' (the
 *   default stays direct_api; only an operator switches it).
 * - tenant_settings.consent_strategy_attestation: who verified it, when, the
 *   reason and where the evidence is kept. Required while the strategy is
 *   merchant_verified (CHECK), cleared when it changes. No address or other
 *   recipient data. Not part of the platform role's column grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->jsonb('consent_strategy_attestation')->nullable();
        });
        DB::statement('ALTER TABLE tenant_settings DROP CONSTRAINT tenant_settings_consent_strategy_check');
        DB::statement("ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_consent_strategy_check CHECK (consent_strategy IN ('direct_api', 'via_reconciliation', 'merchant_verified'))");
        DB::statement("ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_merchant_attestation_check CHECK (consent_strategy <> 'merchant_verified' OR consent_strategy_attestation IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement("UPDATE tenant_settings SET consent_strategy = 'direct_api' WHERE consent_strategy = 'merchant_verified'");
        DB::statement('ALTER TABLE tenant_settings DROP CONSTRAINT tenant_settings_merchant_attestation_check');
        DB::statement('ALTER TABLE tenant_settings DROP CONSTRAINT tenant_settings_consent_strategy_check');
        DB::statement("ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_consent_strategy_check CHECK (consent_strategy IN ('direct_api', 'via_reconciliation'))");
        Schema::table('tenant_settings', fn (Blueprint $table) => $table->dropColumn('consent_strategy_attestation'));
    }
};
