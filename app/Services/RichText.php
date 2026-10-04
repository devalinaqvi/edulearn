<?php

namespace App\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The only way authored HTML enters the application.
 *
 * Sanitizing is an allowlist, never a blocklist: anything not named here is dropped, so a tag or
 * attribute invented by a future browser cannot slip through a pattern that did not anticipate
 * it. Script, style, event handlers, embedded objects and javascript: URLs have no entry.
 *
 * Content is cleaned on the way in and stored clean, so a lesson body read from the database has
 * already passed through this class and can be rendered unescaped. Client-side filtering is a
 * convenience for the author and is never relied upon: the browser is not a trusted party.
 */
class RichText
{
    /** Formatting a lesson author needs, and nothing that can carry behaviour. */
    private const ELEMENTS = [
        'p', 'br', 'strong', 'em', 'u', 's', 'h2', 'h3', 'h4',
        'ul', 'ol', 'li', 'blockquote', 'code', 'pre', 'hr',
    ];

    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitize(?string $html): string
    {
        if (blank($html)) {
            return '';
        }

        $clean = trim(self::sanitizer()->sanitize($html));

        // Markup that carried only stripped content leaves empty shells behind.
        return trim(strip_tags($clean)) === '' && ! str_contains($clean, '<hr') ? '' : $clean;
    }

    /** Whether sanitized markup actually says anything, for "this field is required" checks. */
    public static function isEmpty(?string $html): bool
    {
        return self::sanitize($html) === '';
    }

    /** Plain text for search, extraction and AI sources, which must never receive markup. */
    public static function toPlainText(?string $html): string
    {
        $text = preg_replace('/<(br|\/p|\/h[2-4]|\/li|\/blockquote)[^>]*>/i', "\n", (string) $html) ?? (string) $html;

        return trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private static function sanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer instanceof HtmlSanitizer) {
            return self::$sanitizer;
        }

        $config = new HtmlSanitizerConfig;
        foreach (self::ELEMENTS as $element) {
            $config = $config->allowElement($element);
        }

        // Links carry exactly one attribute, and only to schemes that cannot execute.
        $config = $config
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->forceAttribute('a', 'target', '_blank')
            // dropElement, not blockElement: blocking removes the tag but keeps the text inside
            // it, which would turn <script>alert(1)</script> into a visible "alert(1)" and, worse,
            // leave the contents of a style or form element rendered as prose.
            ->dropElement('script')
            ->dropElement('style')
            ->dropElement('iframe')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('form')
            ->withMaxInputLength(200_000);

        return self::$sanitizer = new HtmlSanitizer($config);
    }
}
