<?php

namespace App\Support;

/**
 * How much text a quotation or invoice may carry so the PDF can run past two pages.
 * Twenty short lines filled one sheet; eighty lines plus notes fill three or more.
 */
final class OdooPaperLimits
{
    public const LINES = 80;

    public const TITLE = 2000;

    public const LINE_NOTES = 8000;

    public const NOTES = 20000;

    /** Space under the hummingbird and logo so the table starts in the same band on every page. */
    public const LETTERHEAD_MARGIN_TOP = 50;

    /** Space above the contact bar so lines never print over the cover footer. */
    public const LETTERHEAD_MARGIN_BOTTOM = 42;

    /** Inset so the RTL description column is not clipped on the right edge. */
    public const LETTERHEAD_MARGIN_SIDE = 12;

    /**
     * @return list<mixed>
     */
    public static function notesRule(): array
    {
        return ['nullable', 'string', 'max:'.self::NOTES];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function lineRules(string $linesRule = 'required'): array
    {
        return [
            'lines' => [$linesRule, 'array', 'min:1', 'max:'.self::LINES],
            'lines.*.title' => ['required', 'string', 'max:'.self::TITLE],
            'lines.*.display_type' => ['nullable', 'in:line_section,line_note'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0', 'required_unless:lines.*.display_type,line_section,line_note'],
            'lines.*.units' => ['nullable', 'numeric', 'min:0.01'],
            'lines.*.product_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.notes' => ['nullable', 'string', 'max:'.self::LINE_NOTES],
            'lines.*.tax_ids' => ['nullable', 'array', 'max:5'],
            'lines.*.tax_ids.*' => ['integer', 'min:1'],
        ];
    }
}
