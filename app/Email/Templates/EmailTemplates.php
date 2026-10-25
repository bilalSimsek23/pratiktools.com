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
        private readonly EmailHtmlPolicy $html,
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
        $this->audit->record(AuditAction::EmailTemplateCreated, 'email_template', $id, ['message_type' => $type->value], $actor);

        return $id;
    }

    /**
     * Validate and store a new draft version. Drafts can be previewed but
     * not used by campaigns until published.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    public function addVersion(string $templateId, string $subject, ?string $html, string $text, Actor $actor, ?string $preheader = null, array $metadata = []): string
    {
        $tenantId = $this->context->requireTenant();
        $template = DB::table('email_templates')->where('tenant_id', $tenantId)->where('id', $templateId)->lockForUpdate()->first()
            ?? throw new TemplateError('NOT_FOUND', 'Unknown template.');
        if ($template->status === 'archived') {
            throw new TemplateError('ARCHIVED', 'An archived template takes no new versions.');
        }
        if ($preheader !== null && mb_strlen($preheader) > 200) {
            throw new TemplateError('INVALID_SYNTAX', 'The preheader is limited to 200 characters.');
        }
        $variables = $this->validate($template->message_type, $subject, $preheader, $html, $text);

        return DB::transaction(function () use ($tenantId, $template, $subject, $preheader, $html, $text, $variables, $metadata, $actor) {
            $version = (int) DB::table('email_template_versions')->where('tenant_id', $tenantId)->where('template_id', $template->id)->max('version') + 1;
            $id = (string) Str::uuid7();
            DB::table('email_template_versions')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'template_id' => $template->id, 'version' => $version, 'subject_template' => $subject, 'preheader' => $preheader,
                'html_template' => $html, 'text_template' => $text, 'variables' => json_encode($variables), 'metadata' => json_encode((object) $metadata),
                'status' => 'draft', 'created_by' => self::by($actor), 'created_at' => now(),
            ]);
            $this->audit->record(AuditAction::EmailTemplateVersionCreated, 'email_template', $template->id, ['version' => $version, 'variables' => $variables], $actor);

            return $id;
        });
    }

    /**
     * Publish a draft version: validated again (syntax, variables, HTML
     * policy, unsubscribe for marketing) and then immutable (DB trigger).
     */
    public function publish(string $versionId, Actor $actor): void
    {
        DB::transaction(function () use ($versionId, $actor) {
            $tenantId = $this->context->requireTenant();
            $v = DB::table('email_template_versions')->where('tenant_id', $tenantId)->where('id', $versionId)->lockForUpdate()->first()
                ?? throw new TemplateError('NOT_FOUND', 'Unknown template version.');
            $template = DB::table('email_templates')->where('tenant_id', $tenantId)->where('id', $v->template_id)->lockForUpdate()->first();
            if ($v->status !== 'draft' || $template->status === 'archived') {
                throw new TemplateError('INVALID_TRANSITION', 'Only a draft version of a live template can be published.');
            }
            $this->validate($template->message_type, $v->subject_template, $v->preheader, $v->html_template, $v->text_template);

            DB::table('email_template_versions')->where('tenant_id', $tenantId)->where('id', $versionId)
                ->update(['status' => 'published', 'published_at' => now(), 'published_by' => self::by($actor)]);
            DB::table('email_templates')->where('tenant_id', $tenantId)->where('id', $template->id)->update(['current_version' => $v->version, 'status' => 'published', 'updated_at' => now()]);
            $this->audit->record(AuditAction::EmailTemplatePublished, 'email_template', $template->id, ['version' => $v->version, 'version_id' => $versionId], $actor);
        });
    }

    /**
     * Archive a template (no new versions, no new campaigns). Campaigns that
     * already reference a published version keep it unchanged.
     */
    public function archive(string $templateId, Actor $actor): void
    {
        $updated = DB::table('email_templates')->where('tenant_id', $this->context->requireTenant())->where('id', $templateId)->where('status', '<>', 'archived')
            ->update(['status' => 'archived', 'updated_at' => now()]);
        if ($updated === 0) {
            throw new TemplateError('NOT_FOUND', 'Unknown or already archived template.');
        }
        $this->audit->record(AuditAction::EmailTemplateArchived, 'email_template', $templateId, [], $actor);
    }

    public function version(string $versionId): object
    {
        return DB::table('email_template_versions as v')->join('email_templates as t', fn ($j) => $j->on('t.id', '=', 'v.template_id')->on('t.tenant_id', '=', 'v.tenant_id'))
            ->where('v.tenant_id', $this->context->requireTenant())->where('v.id', $versionId)
            ->first(['v.*', 't.message_type', 't.name', 't.status as template_status'])
            ?? throw new TemplateError('NOT_FOUND', 'Unknown template version.');
    }

    /**
     * The one rendering pipeline for preview and sending: safe variable
     * substitution, then the HTML allowlist, then the preheader as hidden
     * preview text. No provider is involved.
     *
     * @param  array<string, scalar|null>  $values
     * @return array{subject: string, preheader: ?string, html: ?string, text: string}
     */
    public function render(object $version, array $values): array
    {
        $preheader = isset($version->preheader) && $version->preheader !== ''
            ? $this->renderer->render($version->preheader, $values, TemplateRenderer::SUBJECT) : null;
        $html = $version->html_template === null ? null : $this->html->sanitize($this->renderer->render($version->html_template, $values, TemplateRenderer::HTML));
        if ($html !== null && $preheader !== null) {
            $hidden = '<div style="display:none;max-height:0;overflow:hidden;opacity:0">'.htmlspecialchars($preheader, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</div>';
            $html = preg_match('/<body[^>]*>/i', $html) === 1 ? (string) preg_replace('/(<body[^>]*>)/i', '$1'.$hidden, $html, 1) : $hidden.$html;
        }

        return [
            'subject' => $this->renderer->render($version->subject_template, $values, TemplateRenderer::SUBJECT),
            'preheader' => $preheader,
            'html' => $html,
            'text' => $this->renderer->render($version->text_template, $values, TemplateRenderer::TEXT),
        ];
    }

    /**
     * Sample values for previews: placeholders, never customer data.
     *
     * @return array<string, string>
     */
    public static function sampleValues(?string $campaignName = null): array
    {
        return [
            'customer.first_name' => 'Ayşe', 'customer.last_name' => 'Yılmaz', 'customer.full_name' => 'Ayşe Yılmaz',
            'product.name' => 'Örnek Ürün', 'product.url' => 'https://example.com/urun', 'product.image_url' => 'https://example.com/urun.jpg', 'product.price' => '999,90 TL',
            'coupon.code' => 'ORNEK10', 'coupon.expires_at' => '31.12.2026', 'store.name' => 'Mağaza', 'store.url' => 'https://example.com',
            'unsubscribe_url' => 'https://preview.pratiktools.invalid/u/preview', 'current_year' => (string) now()->year, 'campaign.name' => $campaignName ?? 'Örnek kampanya',
        ];
    }

    /**
     * @return list<string> variables used
     */
    private function validate(string $messageType, string $subject, ?string $preheader, ?string $html, string $text): array
    {
        $variables = array_values(array_unique([...$this->renderer->variables($subject), ...$this->renderer->variables($text),
            ...($html === null ? [] : $this->renderer->variables($html)), ...($preheader === null ? [] : $this->renderer->variables($preheader))]));
        if ($messageType === EmailMessageType::Marketing->value
            && (! in_array('unsubscribe_url', $this->renderer->variables($text), true) || ($html !== null && ! in_array('unsubscribe_url', $this->renderer->variables($html), true)))) {
            throw new TemplateError('UNSUBSCRIBE_MISSING', 'Marketing templates must contain {{ unsubscribe_url }} in every body.');
        }
        if (in_array('unsubscribe_url', $this->renderer->variables($subject.' '.$preheader), true)) {
            throw new TemplateError('INVALID_SYNTAX', 'The unsubscribe link does not belong in the subject or preheader.');
        }
        if ($html !== null && ($issues = $this->html->issues($html)) !== []) {
            throw new TemplateError('UNSAFE_HTML', 'HTML is not allowed: '.implode(', ', $issues).'.');
        }

        return $variables;
    }

    private static function by(Actor $actor): string
    {
        return mb_substr($actor->type.($actor->id !== null ? ':'.$actor->id : ''), 0, 64);
    }
}
