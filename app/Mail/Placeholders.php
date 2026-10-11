<?php

namespace App\Mail;

/**
 * The named placeholders in a message's wording: `{invoice_number}` in the text is replaced by the
 * value of that name.
 */
class Placeholders
{
    /** A name in braces. Anything that reads as one is matched, so a mistyped one can be reported. */
    private const PATTERN = '/\{([A-Za-z][\w.\-]*)\}/';

    /**
     * The text with each placeholder that has a value replaced by it. One with no value is left as typed.
     *
     * @param  array<string, string>  $values
     */
    public static function fill(string $text, array $values): string
    {
        return preg_replace_callback(self::PATTERN, fn (array $match) => $values[$match[1]] ?? $match[0], $text);
    }

    /**
     * As fill(), for a subject: on one line, whatever the values hold.
     *
     * @param  array<string, string>  $values
     */
    public static function fillLine(string $text, array $values): string
    {
        return trim(preg_replace('/\s+/', ' ', self::fill($text, $values)));
    }

    /**
     * The placeholders in the text that are not among the names given, as typed and once each.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function unknown(string $text, array $names): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique(array_map(
            fn (string $name) => '{'.$name.'}',
            array_diff($matches[1], $names),
        )));
    }

    /**
     * Filled wording as paragraphs: split where a line is left blank, with the empty ones a blank
     * value leaves behind dropped. Line breaks inside a paragraph are kept.
     *
     * @return list<string>
     */
    public static function paragraphs(string $text): array
    {
        $paragraphs = preg_split('/\n[ \t]*(?:\n[ \t]*)+/', str_replace(["\r\n", "\r"], "\n", $text));

        return array_values(array_filter(array_map(trim(...), $paragraphs), fn (string $paragraph) => $paragraph !== ''));
    }
}
