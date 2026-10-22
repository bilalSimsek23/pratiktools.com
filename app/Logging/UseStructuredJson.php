<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\ProcessableHandlerInterface;

/**
 * Logging channel tap: JSON lines, platform correlation fields, and
 * redaction of secrets and personal data as the last processor.
 */
final class UseStructuredJson
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true, true, true);
                $formatter->includeStacktraces(false);
                $handler->setFormatter($formatter);
            }

            if ($handler instanceof ProcessableHandlerInterface) {
                // Processors run in reverse order of registration: context first, redaction last.
                $handler->pushProcessor(app(RedactSensitiveData::class));
                $handler->pushProcessor(new AddPlatformContext);
            }
        }
    }
}
