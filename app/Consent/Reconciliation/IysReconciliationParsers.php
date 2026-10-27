<?php

namespace App\Consent\Reconciliation;

use Illuminate\Contracts\Container\Container;

/**
 * The configured report format (consent.reconciliation.format) and its
 * parser. Nothing is registered by default: an unknown format is
 * "not configured", never guessed.
 */
final class IysReconciliationParsers
{
    public function __construct(private readonly Container $container) {}

    public function configuredFormat(): ?string
    {
        $format = config('consent.reconciliation.format');

        return is_string($format) && isset(((array) config('consent.reconciliation.parsers'))[$format]) ? $format : null;
    }

    public function parser(): IysReconciliationParser
    {
        $format = $this->configuredFormat() ?? throw new ReconciliationError('FORMAT_NOT_CONFIGURED', 'No İYS change report format is configured.');
        $parser = $this->container->make(((array) config('consent.reconciliation.parsers'))[$format]);

        return $parser instanceof IysReconciliationParser ? $parser : throw new ReconciliationError('FORMAT_NOT_CONFIGURED', 'The configured parser is not an IysReconciliationParser.');
    }
}
