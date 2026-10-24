<?php

namespace App\Email;

/**
 * Provider-independent, validated e-mail for exactly one recipient (a
 * failure for one recipient never affects another; quotas count
 * recipients anyway). Built from an email_messages row.
 */
final readonly class OutboundEmail
{
    public string $to;

    public string $fromEmail;

    public ?string $fromName;

    public ?string $replyTo;

    public string $subject;

    /** @var array<string, string> */
    public array $headers;

    /** @var array<string, string> */
    public array $tags;

    /**
     * @param  array<array-key, mixed>  $headers  allowlisted names only
     * @param  array<array-key, mixed>  $tags
     */
    public function __construct(
        public string $messageId,
        public string $idempotencyKey,
        public EmailMessageType $type,
        string $to,
        string $fromEmail,
        ?string $fromName,
        ?string $replyTo,
        string $subject,
        public ?string $html,
        public string $text,
        array $headers = [],
        array $tags = [],
    ) {
        $this->to = MessageRules::address($to, 'INVALID_RECIPIENT');
        $this->fromEmail = MessageRules::address($fromEmail, 'INVALID_FROM');
        $this->fromName = MessageRules::line($fromName, 120, 'INVALID_FROM_NAME', required: false);
        $this->replyTo = $replyTo === null || $replyTo === '' ? null : MessageRules::address($replyTo, 'INVALID_REPLY_TO');
        $this->subject = (string) MessageRules::line($subject, (int) config('email.max_subject_length'), 'INVALID_SUBJECT');
        $this->headers = MessageRules::headers($headers);
        $this->tags = MessageRules::tags($tags);

        if (trim($text) === '' || strlen($text) > (int) config('email.max_text_bytes') || ! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidEmailMessage('INVALID_TEXT_BODY', 'A UTF-8 text body within the size limit is required.');
        }
        if ($html !== null && (strlen($html) > (int) config('email.max_html_bytes') || ! mb_check_encoding($html, 'UTF-8'))) {
            throw new InvalidEmailMessage('INVALID_HTML_BODY', 'HTML body is too large or not UTF-8.');
        }
    }

    public function sizeBytes(): int
    {
        return strlen($this->subject) + strlen($this->text) + strlen((string) $this->html);
    }

    public function domain(): string
    {
        return substr($this->fromEmail, strrpos($this->fromEmail, '@') + 1);
    }
}
