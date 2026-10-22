<?php

namespace Tests\Feature;

use App\Integrations\ProviderRegistry;
use App\Jobs\ProcessWebhook;
use App\Models\WebhookInboxItem;
use App\Webhooks\Contracts\WebhookHandler;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class WebhookInboxTest extends TestCase
{
    use InteractsWithTenants;

    public function test_webhook_is_stored_then_queued_and_duplicates_are_ignored(): void
    {
        Queue::fake();
        [$a] = $this->createTenant('alpha');
        $url = "/webhooks/klaviyo/{$a->public_id}";

        $this->postJson($url, ['id' => 'evt_1', 'type' => 'profile.updated', 'data' => ['x' => 1]], ['X-Signature' => 'abc'])
            ->assertAccepted()->assertJson(['status' => 'accepted']);
        $this->postJson($url, ['id' => 'evt_1', 'type' => 'profile.updated', 'data' => ['x' => 1]])
            ->assertOk()->assertJson(['status' => 'duplicate']);

        Queue::assertPushed(ProcessWebhook::class, 1);
        Queue::assertPushedOn('webhooks', ProcessWebhook::class);

        $items = $this->inTenant($a, fn () => WebhookInboxItem::query()->get());
        $this->assertCount(1, $items);
        $this->assertSame('evt_1', $items[0]->external_event_id);
        $this->assertSame('pending', $items[0]->processing_status);
        $this->assertSame('not_configured', $items[0]->signature_status);
        $this->assertTrue($items[0]->headers_minimized['has_signature']);
        $this->assertArrayNotHasKey('x-signature', $items[0]->headers_minimized);
    }

    public function test_same_event_id_for_different_tenants_is_not_a_duplicate(): void
    {
        Queue::fake();
        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');

        $this->postJson("/webhooks/klaviyo/{$a->public_id}", ['id' => 'evt_1'])->assertAccepted();
        $this->postJson("/webhooks/klaviyo/{$b->public_id}", ['id' => 'evt_1'])->assertAccepted();

        Queue::assertPushed(ProcessWebhook::class, 2);
    }

    public function test_processing_runs_the_handler_once_even_if_dispatched_twice(): void
    {
        [$a] = $this->createTenant('alpha');
        $handler = new class implements WebhookHandler
        {
            public int $calls = 0;

            public function handle(WebhookInboxItem $item): void
            {
                $this->calls++;
            }
        };
        app(ProviderRegistry::class)->registerWebhookHandler('klaviyo', $handler);

        $this->postJson("/webhooks/klaviyo/{$a->public_id}", ['id' => 'evt_9'])->assertAccepted(); // processed synchronously (sync queue)
        $id = $this->inTenant($a, fn () => WebhookInboxItem::query()->value('id'));
        dispatch_sync(new ProcessWebhook($a->id, $id));

        $this->assertSame(1, $handler->calls);
        $item = $this->inTenant($a, fn () => WebhookInboxItem::query()->find($id));
        $this->assertSame('processed', $item->processing_status);
        $this->assertSame(1, $item->attempts);
    }

    public function test_webhook_without_handler_is_marked_ignored(): void
    {
        [$a] = $this->createTenant('alpha');

        $this->postJson("/webhooks/onesignal/{$a->public_id}", ['event_id' => 'n1'])->assertAccepted();

        $this->assertSame('ignored', $this->inTenant($a, fn () => WebhookInboxItem::query()->value('processing_status')));
    }

    public function test_rejects_unknown_tenant_provider_bad_json_and_oversized_payloads(): void
    {
        Queue::fake();
        [$a] = $this->createTenant('alpha');

        $this->postJson('/webhooks/klaviyo/doesnotexist0000000000', ['id' => 1])->assertNotFound();
        $this->postJson("/webhooks/unknownprov/{$a->public_id}", ['id' => 1])->assertNotFound();
        $this->call('POST', "/webhooks/klaviyo/{$a->public_id}", [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json')->assertStatus(400);

        config(['platform.webhooks.max_payload_bytes' => 100]);
        $this->postJson("/webhooks/klaviyo/{$a->public_id}", ['blob' => str_repeat('x', 200)])->assertStatus(413);

        Queue::assertNothingPushed();
    }

    public function test_webhooks_are_not_served_on_the_admin_host(): void
    {
        [$a] = $this->createTenant('alpha');

        $this->postJson('http://'.config('platform.admin_domain')."/webhooks/klaviyo/{$a->public_id}", ['id' => 'x'])->assertNotFound();
    }
}
