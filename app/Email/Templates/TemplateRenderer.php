<?php

namespace App\Email\Templates;

/**
 * Logic-less, safe template substitution. The only construct is
 *
 *     {{ customer.first_name }}            or
 *     {{ customer.first_name | default: "Değerli müşterimiz" }}
 *
 * - only variables on the allowlist (email.templates.variables);
 * - no expressions, filters, loops, includes, PHP, Blade or eval: any
 *   other "{{" / "}}" is a syntax error, never passed through;
 * - escaping by context: HTML-escaped in HTML, control characters removed
 *   in text, a single clean line in subjects;
 * - URL variables must be https URLs;
 * - a missing value without a default is an error (strict), so a
 *   message never goes out with an empty greeting or a broken link.
 */
final class TemplateRenderer
{
    public const HTML = 'html';

    public const TEXT = 'text';

    public const SUBJECT = 'subject';

    private const TAG = '/\{\{\s*([a-z_]+(?:\.[a-z_]+)?)\s*(?:\|\s*default:\s*"([^"{}\r\n]{0,200})"\s*)?\}\}/';

    /**
     * Variables a template uses; throws on syntax errors or unknown names.
     *
     * @return list<string>
     */
    public function variables(string $template): array
    {
        // "{{{ raw }}}" (unescaped output elsewhere) is refused, not treated as escaped.
        $stripped = preg_replace(self::TAG, '', $template);
        if (! is_string($stripped) || str_contains($stripped, '{{') || str_contains($stripped, '}}') || str_contains($template, '{{{') || str_contains($template, '}}}')) {
            throw new TemplateError('INVALID_SYNTAX', 'Only {{ variable }} and {{ variable | default: "text" }} are allowed.');
        }

        preg_match_all(self::TAG, $template, $m);
        $allowed = (array) config('email.templates.variables');
        foreach ($m[1] as $name) {
            if (! in_array($name, $allowed, true)) {
                throw new TemplateError('UNKNOWN_VARIABLE', "Variable [{$name}] is not allowed in templates.");
            }
        }

        return array_values(array_unique($m[1]));
    }

    /**
     * @param  array<string, scalar|null>  $values  flat "a.b" => value
     */
    public function render(string $template, array $values, string $context): string
    {
        $this->variables($template);
        $urls = (array) config('email.templates.url_variables');

        $out = preg_replace_callback(self::TAG, function (array $m) use ($values, $context, $urls) {
            $name = $m[1];
            $value = $values[$name] ?? null;
            $value = $value === null || $value === '' ? (isset($m[2]) && $m[2] !== '' ? $m[2] : null) : (string) $value;
            if ($value === null) {
                throw new TemplateError('MISSING_VARIABLE', "Variable [{$name}] has no value and no default.");
            }
            if (in_array($name, $urls, true) && (filter_var($value, FILTER_VALIDATE_URL) === false || ! str_starts_with($value, 'https://'))) {
                throw new TemplateError('INVALID_URL_VARIABLE', "Variable [{$name}] must be an https URL.");
            }

            return match ($context) {
                self::HTML => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
                self::SUBJECT => trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value)),
                default => (string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $value),
            };
        }, $template);

        return (string) $out;
    }
}
