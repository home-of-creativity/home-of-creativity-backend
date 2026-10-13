<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Throwable;

/**
 * Terms written in Odoo's editor often end with an empty table or empty lines. In the PDF
 * that tail can start a page of its own with only a grey box on it, so it is cut off.
 * Anything it cannot read is returned unchanged: it never shortens real text.
 */
final class OdooNoteHtml
{
    /** Elements that print something even with no text in them. */
    private const VISIBLE = ['img', 'svg', 'video', 'iframe', 'hr', 'canvas', 'object', 'embed', 'input'];

    public static function trimEmptyTail(string $html): string
    {
        if (trim($html) === '' || ! str_contains($html, '<')) {
            return rtrim($html);
        }

        try {
            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $loaded = $document->loadHTML(
                '<?xml encoding="utf-8"?><div id="hoc-note-root">'.$html.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
            );
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $root = $document->getElementById('hoc-note-root');
            if (! $loaded || ! $root instanceof DOMElement) {
                return $html;
            }

            if (! self::trimNode($root)) {
                return $html;
            }

            $out = '';
            foreach ($root->childNodes as $child) {
                $out .= $document->saveHTML($child);
            }

            // Real text never disappears: if the visible text changed, keep the original.
            return self::text($out) === self::text($html) ? $out : $html;
        } catch (Throwable) {
            return $html;
        }
    }

    /** Removes empty nodes from the end of $node; true when something was removed. */
    private static function trimNode(DOMNode $node): bool
    {
        $changed = false;
        while ($node->lastChild !== null) {
            $last = $node->lastChild;
            if (self::isEmpty($last)) {
                $node->removeChild($last);
                $changed = true;

                continue;
            }
            if ($last instanceof DOMElement) {
                $changed = self::trimNode($last) || $changed;
            }
            break;
        }

        return $changed;
    }

    private static function isEmpty(DOMNode $node): bool
    {
        if ($node instanceof DOMText) {
            return self::blank($node->textContent);
        }
        if (! $node instanceof DOMElement) {
            // Comments and the like print nothing.
            return true;
        }
        if (in_array(strtolower($node->nodeName), self::VISIBLE, true)) {
            return false;
        }
        foreach (self::VISIBLE as $tag) {
            if ($node->getElementsByTagName($tag)->length > 0) {
                return false;
            }
        }

        return self::blank($node->textContent);
    }

    private static function blank(string $text): bool
    {
        return trim(str_replace("\u{00A0}", ' ', $text)) === '';
    }

    private static function text(string $html): string
    {
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/[\s\x{00A0}]+/u', '', $plain);
    }
}
