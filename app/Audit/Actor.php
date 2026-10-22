<?php

namespace App\Audit;

final readonly class Actor
{
    public function __construct(
        public string $type,
        public ?string $id = null,
    ) {}

    public static function user(string $id): self
    {
        return new self('user', $id);
    }

    public static function platformAdmin(string $id): self
    {
        return new self('platform_admin', $id);
    }

    public static function system(?string $id = null): self
    {
        return new self('system', $id);
    }

    public static function console(?string $command = null): self
    {
        return new self('console', $command);
    }

    public static function job(?string $job = null): self
    {
        return new self('job', $job);
    }
}
