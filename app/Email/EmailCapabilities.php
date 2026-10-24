<?php

namespace App\Email;

final readonly class EmailCapabilities
{
    /** @var list<EmailCapability> */
    public array $all;

    public function __construct(EmailCapability ...$capabilities)
    {
        $this->all = array_values(array_unique($capabilities, SORT_REGULAR));
    }

    public function has(EmailCapability $capability): bool
    {
        return in_array($capability, $this->all, true);
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(fn (EmailCapability $c) => $c->value, $this->all);
    }
}
