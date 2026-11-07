<?php

namespace Tests;

use App\Site\InitialContent;
use App\Site\InitialReferences;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Feature tests run against the real PostgreSQL schema (with row-level
 * security) migrated by the owner role, while the application itself uses
 * the non-owner runtime role exactly as in production.
 */
abstract class TestCase extends BaseTestCase
{
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$migrated) {
            $this->artisan('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true])->assertSuccessful();
            self::$migrated = true;
        }

        DB::connection('pgsql_owner')->statement(
            'TRUNCATE tenants, users, platform_admins, audit_logs, webhook_inbox, personal_access_tokens, failed_jobs, job_batches, password_reset_tokens CASCADE'
        );
        // Website tables are not tenant data (no tenant FK): reset them to the
        // installed starting content.
        DB::connection('pgsql_owner')->statement('TRUNCATE site_reference_contents, site_references, site_content_relations, site_contents, site_categories, site_media, site_settings, site_events_daily CASCADE');
        InitialContent::install();
        InitialReferences::install();
        // The Super Admin e-mail switch starts open in tests, so the configured
        // EMAIL_SENDING_MODE alone decides (production starts it "off").
        DB::connection('pgsql_owner')->table('platform_settings')->where('key', 'email.sending_mode')->update(['value' => 'live', 'updated_by' => null]);

        $this->app->make(TenantContext::class)->clear();
    }

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->clear();

        foreach (array_keys(DB::getConnections()) as $name) {
            DB::disconnect($name);
        }

        parent::tearDown();
    }
}
