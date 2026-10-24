<?php

namespace App\Email;

use App\Audit\Actor;
use App\Email\Contracts\EmailProvider;
use App\Email\Contracts\EmailProviderFactory;
use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Picks the delivery provider of a tenant. A tenant may connect several
 * e-mail providers; settings.email_role says which is "primary" and which
 * "secondary" (the only one is primary by default).
 *
 * There is deliberately no automatic fallback send: a message is pinned
 * to the integration chosen when it was created, and a failure is never
 * retried on another provider. A timeout can mean the first provider
 * already accepted the mail, so a silent fallback would deliver it twice
 * and spread reputation across two sending domains/IPs. Switching
 * providers is an explicit, per-campaign decision.
 */
final class EmailProviderResolver
{
    /** @var array<string, EmailProviderFactory> */
    private array $factories = [];

    public function __construct(private readonly TenantContext $context) {}

    public function register(string $provider, EmailProviderFactory $factory): void
    {
        $this->factories[$provider] = $factory;
    }

    public function supports(string $provider): bool
    {
        return isset($this->factories[$provider]);
    }

    /**
     * E-mail integrations of the tenant, primary first.
     *
     * @return Collection<int, TenantIntegration>
     */
    public function integrations(): Collection
    {
        $this->context->requireTenant();

        return TenantIntegration::query()->where('capability', 'email')->get()
            ->filter(fn (TenantIntegration $i) => $this->supports($i->provider))
            ->sortBy(fn (TenantIntegration $i) => [($i->settings['email_role'] ?? 'primary') === 'primary' ? 0 : 1, $i->created_at])
            ->values();
    }

    public function primary(): ?TenantIntegration
    {
        return $this->integrations()->first(fn (TenantIntegration $i) => ($i->settings['email_role'] ?? 'primary') === 'primary');
    }

    public function secondary(): ?TenantIntegration
    {
        return $this->integrations()->first(fn (TenantIntegration $i) => ($i->settings['email_role'] ?? null) === 'secondary');
    }

    public function provider(TenantIntegration $integration, string $purpose, ?Actor $actor = null): EmailProvider
    {
        if ($integration->tenant_id !== $this->context->requireTenant() || $integration->capability !== 'email') {
            throw new InvalidArgumentException('Not an e-mail integration of the current tenant.');
        }
        $factory = $this->factories[$integration->provider] ?? throw new InvalidArgumentException("No e-mail provider connector for [{$integration->provider}].");

        return $factory->make($integration, $purpose, $actor);
    }
}
