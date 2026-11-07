<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * platform_settings: platform-wide switches managed in Super Admin, not
 * tenant data (no tenant_id, no RLS). Only known keys with known values
 * (CHECK constraints), so nothing else can be stored here.
 *
 * - email.sending_mode (off | test_only | live): the panel half of the
 *   platform e-mail switch. The effective platform mode is the stricter of
 *   this value and EMAIL_SENDING_MODE (now an upper bound), so the database
 *   alone never opens sending. Starts "off".
 *
 * Grants: the runtime role reads it (the send path checks it before every
 * message); the platform role reads it and may update only the value and
 * who/when; neither can insert or delete keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE platform_settings (
                key varchar(64) PRIMARY KEY,
                value varchar(32) NOT NULL,
                updated_by uuid NULL,
                updated_at timestamp(0) without time zone NULL,
                CONSTRAINT platform_settings_key_check CHECK (key IN ('email.sending_mode')),
                CONSTRAINT platform_settings_email_mode_check CHECK (key <> 'email.sending_mode' OR value IN ('off', 'test_only', 'live'))
            )
        SQL);
        DB::table('platform_settings')->insert(['key' => 'email.sending_mode', 'value' => 'off', 'updated_at' => now()]);

        DB::statement('GRANT SELECT ON platform_settings TO '.Rls::appRole());
        DB::statement('GRANT SELECT ON platform_settings TO '.Rls::platformRole());
        DB::statement('GRANT UPDATE (value, updated_by, updated_at) ON platform_settings TO '.Rls::platformRole());
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS platform_settings');
    }
};
