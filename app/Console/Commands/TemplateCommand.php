<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Email\EmailMessageType;
use App\Email\Templates\EmailTemplates;
use App\Email\Templates\TemplateError;
use Illuminate\Console\Command;

/**
 *   template create --name --type=marketing|transactional
 *   template version --template --subject --text-file [--html-file] [--preheader]
 *   template publish|preview --version     template archive --template
 */
final class TemplateCommand extends Command
{
    use RunsInTenantContext;

    protected $signature = 'template {action : create|version|publish|archive|preview} {--tenant=} {--name=} {--type=marketing} {--template=} {--version-id=}
        {--subject=} {--preheader=} {--text-file=} {--html-file=}';

    protected $description = 'E-mail templates: create, add draft version, publish, archive, render preview (nothing is sent)';

    public function handle(EmailTemplates $templates): int
    {
        $actor = Actor::console('template:'.$this->argument('action'));

        return $this->inTenant(function () use ($templates, $actor) {
            try {
                match ($this->argument('action')) {
                    'create' => $this->info('Template '.$templates->create((string) $this->option('name'), EmailMessageType::from((string) $this->option('type')), $actor)),
                    'version' => $this->info('Draft version '.$templates->addVersion((string) $this->option('template'), (string) $this->option('subject'),
                        $this->option('html-file') ? (string) file_get_contents((string) $this->option('html-file')) : null, (string) file_get_contents((string) $this->option('text-file')),
                        $actor, $this->option('preheader') ?: null)),
                    'publish' => $templates->publish((string) $this->option('version-id'), $actor),
                    'archive' => $templates->archive((string) $this->option('template'), $actor),
                    'preview' => $this->line(json_encode($templates->render($templates->version((string) $this->option('version-id')), EmailTemplates::sampleValues()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
                    default => throw new TemplateError('INVALID', 'Unknown action.'),
                };
            } catch (TemplateError $e) {
                $this->error("[{$e->reason}] {$e->getMessage()}");

                return self::FAILURE;
            }

            return self::SUCCESS;
        });
    }
}
