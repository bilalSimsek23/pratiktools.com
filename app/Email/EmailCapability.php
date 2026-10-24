<?php

namespace App\Email;

/**
 * What a delivery provider can do. The core never assumes a capability a
 * provider does not declare (for example, no open tracking unless the
 * provider reports opens).
 */
enum EmailCapability: string
{
    case SendEmail = 'send_email';
    // One API call carrying many different messages.
    case SendBatch = 'send_batch';
    case ProviderTemplate = 'provider_template';
    case RawHtml = 'raw_html';
    case CustomHeaders = 'custom_headers';
    case Tags = 'tags';
    case WebhookEvents = 'webhook_events';
    case BounceEvents = 'bounce_events';
    case ComplaintEvents = 'complaint_events';
    case DeliveryEvents = 'delivery_events';
    case OpenTracking = 'open_tracking';
    case ClickTracking = 'click_tracking';
}
