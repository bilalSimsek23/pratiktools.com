<?php

namespace App\Push;

use InvalidArgumentException;

/**
 * Provider-independent push content. Deep links are passed through as
 * given: the app's scheme is not guessed.
 */
final readonly class PushMessage
{
    /**
     * @param  array<string, string>  $metadata  campaign_id, automation_run_id, utm_* ...
     */
    public function __construct(
        public string $title,
        public string $body,
        public ?string $imageUrl = null,
        public ?string $webUrl = null,
        public ?string $deepLink = null,
        public ?string $campaignId = null,
        public ?string $automationRunId = null,
        public array $metadata = [],
    ) {
        if (trim($title) === '' || trim($body) === '') {
            throw new InvalidArgumentException('Push title and body are required.');
        }
        if (mb_strlen($title) > 120 || mb_strlen($body) > 1000) {
            throw new InvalidArgumentException('Push title or body is too long.');
        }
        foreach ([$imageUrl, $webUrl] as $url) {
            if ($url !== null && ! str_starts_with($url, 'https://')) {
                throw new InvalidArgumentException('Push image and web URLs must be https.');
            }
        }
    }

    /**
     * Safe summary for storage and audit (no personal data in content is
     * assumed for tests; lengths only).
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return array_filter([
            'title_length' => mb_strlen($this->title),
            'body_length' => mb_strlen($this->body),
            'has_image' => $this->imageUrl !== null,
            'has_web_url' => $this->webUrl !== null,
            'has_deep_link' => $this->deepLink !== null,
            'campaign_id' => $this->campaignId,
            'automation_run_id' => $this->automationRunId,
        ], fn ($v) => $v !== null);
    }
}
