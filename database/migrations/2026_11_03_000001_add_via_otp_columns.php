<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ViA OTP: the provider reference of a started OTP links start and
 * verification; attempts are counted. No OTP code is ever stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('via_consent_requests', function (Blueprint $table) {
            $table->string('provider_reference', 128)->nullable();
            $table->timestamp('otp_started_at')->nullable();
            $table->unsignedSmallInteger('otp_attempts')->default(0);
            $table->unique(['tenant_id', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('via_consent_requests', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'provider_reference']);
            $table->dropColumn(['provider_reference', 'otp_started_at', 'otp_attempts']);
        });
    }
};
