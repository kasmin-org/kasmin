<?php

namespace App\Support\Mail;

use App\Models\MailTemplate;
use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * Renders mail templates with a sandboxed Twig (MT03, MT11): only if/for/set, a few filters, no functions, no method
 * or property access on objects – the context consists of plain arrays built by Placeholders, which never contains
 * confidential fields (MT04). Templates edited via the API therefore cannot execute code.
 */
class TemplateRenderer
{
    private const TAGS = ['if', 'for', 'set'];

    private const FILTERS = ['escape', 'e', 'upper', 'lower', 'capitalize', 'title', 'default', 'join', 'length', 'nl2br', 'first', 'last', 'trim'];

    /**
     * @param  array<string, mixed>  $context
     * @return array{subject: string, html: string, text: string}
     */
    public function render(MailTemplate $template, string $locale, array $context): array
    {
        $content = $template->content($locale);
        if ($content === null) {
            throw new MailException("Template {$template->key} has no content.");
        }

        return $this->renderStrings($content->subject, $content->html, $content->text, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{subject: string, html: string, text: string}
     */
    public function renderStrings(string $subject, string $html, ?string $text, array $context): array
    {
        $twig = $this->twig(['subject' => $subject, 'html' => $html, 'text' => (string) $text]);
        try {
            $renderedHtml = $twig->render('html', $context);

            return [
                'subject' => trim(html_entity_decode(strip_tags($twig->render('subject', $context)), ENT_QUOTES)),
                'html' => $renderedHtml,
                'text' => ($text !== null && trim($text) !== '') ? $twig->render('text', $context) : self::toText($renderedHtml),
            ];
        } catch (TwigError $e) {
            throw new MailException($e->getRawMessage());
        }
    }

    /**
     * Validates a template against a complete sample context; unknown placeholders or forbidden constructs block
     * saving (MT03).
     *
     * @return list<string> error messages, empty when valid
     */
    public function validate(string $subject, string $html, ?string $text, ?string $group = null): array
    {
        $problems = [...TemplateProblems::find($subject), ...TemplateProblems::find($html), ...TemplateProblems::find((string) $text)];
        if ($problems !== []) {
            return array_values(array_unique($problems));
        }
        try {
            $this->renderStrings($subject, $html, $text, Placeholders::sample($group));

            return [];
        } catch (MailException $e) {
            return [TemplateProblems::translate($e->getMessage(), $group, $subject.' '.$html.' '.$text)];
        }
    }

    public static function toText(string $html): string
    {
        $text = preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|h[1-6]|li|tr)>/i', '/<li[^>]*>/i'], ["\n", "\n\n", '- '], $html) ?? $html;
        $text = preg_replace_callback('/<a\s[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', fn ($m) => trim(strip_tags($m[2])).' ('.$m[1].')', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace('/[ \t]+/', ' ', $text)));
    }

    /** @param array<string, string> $templates */
    private function twig(array $templates): Environment
    {
        $twig = new Environment(new ArrayLoader($templates), ['autoescape' => 'html', 'strict_variables' => true, 'cache' => false]);
        $twig->addExtension(new SandboxExtension(new SecurityPolicy(self::TAGS, self::FILTERS, [], [], []), true));

        return $twig;
    }
}
