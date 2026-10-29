<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Support\ProductionGuard;
use App\Tenancy\Exceptions\TenantContextMissing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class PlatformFoundationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_primary_keys_are_uuid_v7(): void
    {
        [$a, $owner] = $this->createTenant('alpha');
        $customer = $this->createCustomer($a, 'c@alpha.test');

        foreach ([$a->id, $owner->id, $customer->id] as $id) {
            $this->assertTrue(Str::isUuid($id));
            $this->assertSame('7', $id[14], "{$id} is not a version 7 UUID");
        }
    }

    public function test_tenant_data_indexes_start_with_tenant_id(): void
    {
        $offenders = collect(DB::connection('pgsql_owner')->select(<<<'SQL'
            SELECT t.relname AS table_name, i.relname AS index_name, a.attname AS first_column
            FROM pg_index x
            JOIN pg_class t ON t.oid = x.indrelid
            JOIN pg_class i ON i.oid = x.indexrelid
            LEFT JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = x.indkey[0]
            WHERE t.relnamespace = 'public'::regnamespace
              AND NOT x.indisprimary
              AND t.relname IN ('tenant_users', 'tenant_data_keys', 'tenant_integrations', 'customers', 'audit_logs', 'webhook_inbox')
        SQL))->reject(fn ($row) => $row->first_column === 'tenant_id'
            || in_array($row->index_name, ['audit_logs_action_created_at_index', 'tenant_users_user_id_index', 'webhook_inbox_idempotency'], true));

        $this->assertSame([], $offenders->map(fn ($r) => "{$r->table_name}.{$r->index_name}")->values()->all());
    }

    public function test_production_guard_refuses_unsafe_configuration(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true, 'session.secure' => false, 'platform.vault.kek_driver' => 'local']);

        try {
            ProductionGuard::assertSafe();
            $this->fail('Unsafe production configuration must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('APP_DEBUG', $e->getMessage());
            $this->assertStringContainsString('SESSION_SECURE_COOKIE', $e->getMessage());
            $this->assertStringContainsString('KEK', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_security_headers_are_present_on_api_responses(): void
    {
        $response = $this->getJson('/healthz');

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
    }

    public function test_incoming_request_id_is_only_accepted_when_it_is_a_uuid(): void
    {
        $uuid = (string) Str::uuid7();

        $this->getJson('/healthz', ['X-Request-Id' => $uuid])->assertHeader('X-Request-Id', $uuid);
        $this->assertNotSame('<script>', $this->getJson('/healthz', ['X-Request-Id' => '<script>'])->headers->get('X-Request-Id'));
    }

    public function test_no_debug_or_default_routes_are_exposed(): void
    {
        // F7.5: the root is the public website, never the framework welcome page.
        $this->get('/')->assertOk()->assertSee('PratikTools')->assertDontSee('laravel.com/docs')->assertDontSee('Laracasts');
        $this->get('/up')->assertNotFound();
        $this->get('/_debugbar')->assertNotFound();
        $this->get('/telescope')->assertNotFound();
    }

    public function test_tenant_record_model_cannot_be_created_without_context(): void
    {
        $this->expectException(TenantContextMissing::class);

        Customer::query()->create(['email' => 'x@example.test']);
    }
}
