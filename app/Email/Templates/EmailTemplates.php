<?php

namespace App\Email\Templates;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Email\EmailMessageType;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Templates live in the core (never as provider template ids). Versions
 * are immutable: a change is a new version, and messages reference the
 * version they were rendered from. Every read is tenant-scoped (and RLS
 * enforced), so another tenant's template id simply does not exist here.
 */
final class EmailTemplates
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TemplateRenderer $renderer,
        private readonly AuditLogger $audit,
    ) {}

    public function create(string $name, EmailMessageType $type, Actor $actor): string
    {
        if ($type === EmailMessageType::Test || trim($name) === '' || mb_strlen($name) > 120) {
            throw new TemplateError('INVALID_TEMPLATE', 'A template needs a name and type marketing or transactional.');
        }
        $id = (string) Str::uuid7();
        DB::table('email_templates')->insert(['id' => $id, 'tenant_id' => $this->context->requireTenant(), 'name' => trim($name), 'message_type' => $type->value,
            'status' => 'draft', 'created_by' => self::by($actor), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /**
     * Validate and store a new version, making it current.
     */
    public function addVersion(string $templateId, string $subject, ?string $html, string $text, Actor $actor): string
    {
        $tenantId = $this->context->requireTenant();
        $template = DB::table('email_templates')->where('tenant_id', $tenantId)->where('id', $templateId)->lockForUpdate()->first()
            ?? throw new TemplateError('NOT_FOUND', 'Unknown template.');

        $variables = array_values(array_unique([...$this->renderer->variables($subject), ...$this->renderer->variables($text), ...($html === null ? [] : $this->renderer->variables($html))]));
        if ($template->message_type === EmailMessageType::Marketing->value
            && (! in_array('unsubscribe_url', $this->renderer->variables($text), true) || ($html !== null && ! in_array('unsubscribe_url', $this->renderer->variables($html), true)))) {
            throw new TemplateError('UNSUBSCRIBE_MISSING', 'Marketing templates must contain {{ unsubscribe_url }} in every body.');
        }
        if (in_array('unsubscribe_url', $this->renderer->variables($subject), true)) {
            throw new TemplateError('INVALID_SYNTAX', 'The unsubscribe link does not belong in the subject.');
        }

        return DB::transaction(function () use ($tenantId, $template, $subject, $html, $text, $variables, $actor) {
            $version = (int) DB::table('email_template_versions')->where('tenant_id', $tenantId)->where('template_id', $template->id)->max('version') + 1;
            $id = (string) Str::uuid7();
            DB::table('email_template_versions')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'template_id' => $template->id, 'version' => $version, 'subject_template' => $subject,
                'html_template' => $html, 'text_template' => $text, 'variables' => json_encode($variables), 'status' => 'active', 'created_by' => self::by($actor), 'created_at' => now(),
            ]);
            DB::table('email_templates')->where('tenant_id', $tenantId)->where('id', $template->id)->update(['current_version' => $version, 'status' => 'active', 'updated_at' => now()]);
            $this->audit->record(AuditAction::EmailTemplateVersionCreated, 'email_template', $template->id, ['version' => $version, 'variables' => $variables], $actor);

            return $id;
        });
    }

    public function version(string $versionId): object
    {
        return DB::table('email_template_versions as v')->join('email_templates as t', fn ($j) => $j->on('t.id', '=', 'v.template_id')->on('t.tenant_id', '=', 'v.tenant_id'))
            ->where('v.tenant_id', $this->context->requireTenant())->where('v.id', $versionId)
            ->first(['v.*', 't.message_type', 't.name'])
            ?? throw new TemplateError('NOT_FOUND', 'Unknown template version.');
    }

    /**
     * @param  array<string, scalar|null>  $values
     * @return array{subject: string, html: ?string, text: string}
     */
    public function render(object $version, array $values): array
    {
        return [
            'subject' => $this->renderer->render($version->subject_template, $values, TemplateRenderer::SUBJECT),
            'html' => $version->html_template === null ? null : $this->renderer->render($version->html_template, $values, TemplateRenderer::HTML),
            'text' => $this->renderer->render($version->text_template, $values, TemplateRenderer::TEXT),
        ];
    }

    private static function by(Actor $actor): string
    {
        return mb_substr($actor->type.($actor->id !== null ? ':'.$actor->id : ''), 0, 64);
    }
}
