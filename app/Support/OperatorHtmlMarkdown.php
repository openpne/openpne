<?php

declare(strict_types=1);

namespace App\Support;

use DOMComment;
use DOMElement;
use DOMNode;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use Masterminds\HTML5;

/**
 * Rewrites operator HTML as Markdown for MarkdownText::renderOperator(), keeping what Markdown can
 * say and naming what it cannot (docs/internals/body-text.md, "Operator text").
 */
final class OperatorHtmlMarkdown
{
    private const REMOVED = ['script', 'style', 'iframe', 'noscript', 'meta', 'link', 'base', 'title', 'object', 'embed', 'template', 'svg', 'math', 'textarea', 'select', 'button'];

    private const UNWRAPPED = ['div', 'center', 'section', 'article', 'header', 'footer', 'nav', 'aside', 'main', 'form', 'fieldset'];

    private const BLOCKS = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'ul', 'ol', 'dl', 'table', 'blockquote', 'pre'];

    /** Held to the attribute names the renderer's sanitizer lets through, so a name it drops is reported here. */
    private const KEPT_ATTRIBUTES = ['a' => ['href'], 'img' => ['src', 'alt', 'title']];

    public static function convert(string $html): ConvertedOperatorHtml
    {
        if (trim($html) === '') {
            return new ConvertedOperatorHtml('');
        }

        if (preg_match('~<[a-z!/][^>]*>~i', $html) !== 1) {
            // OpenPNE 3 printed these fields with no nl2br, so the browser ran the lines together.
            $text = self::collapse(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return new ConvertedOperatorHtml(MarkdownEscape::plain(htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8')));
        }

        // An HTML5 tree builder, so a stray `</br>` is the line break a browser made of it.
        $parser = new HTML5(['disable_html_ns' => true]);
        $fragment = $parser->loadHTMLFragment($html);

        $elements = [];
        $attributes = [];
        self::prune($fragment, $elements, $attributes);
        self::paragraphs($fragment);

        $converter = new HtmlConverter([
            'strip_tags' => true,
            'strip_placeholder_links' => true,
            'remove_nodes' => implode(' ', self::REMOVED),
            // `<br>` becomes a bare newline, which MarkdownText renders back as `<br>`.
            'hard_break' => true,
            'header_style' => 'atx',
            'use_autolinks' => false,
        ]);

        $converter->getEnvironment()->addConverter(new TableConverter);

        $markdown = $converter->convert($parser->saveHTML($fragment));
        $markdown = trim((string) preg_replace("/\n{3,}/", "\n\n", $markdown));
        // A line of `=` after a `<br>` would turn the line above it into a setext heading.
        $markdown = (string) preg_replace('/^(\h*)(=+\h*)$/mu', '$1\\\\$2', $markdown);

        ksort($elements);
        ksort($attributes);

        return new ConvertedOperatorHtml($markdown, $elements, $attributes);
    }

    /**
     * @param  array<string, int>  $elements
     * @param  array<string, int>  $attributes
     */
    private static function prune(DOMNode $node, array &$elements, array &$attributes): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $node->removeChild($child);
            } elseif ($child instanceof DOMElement) {
                $name = strtolower($child->nodeName);

                if (in_array($name, self::REMOVED, true)) {
                    $elements[$name] = ($elements[$name] ?? 0) + 1;
                    $node->removeChild($child);

                    continue;
                }

                foreach (iterator_to_array($child->attributes) as $attribute) {
                    $key = strtolower($attribute->nodeName);

                    if (! in_array($key, self::KEPT_ATTRIBUTES[$name] ?? [], true) || self::isRefusedScheme($key, $attribute->nodeValue ?? '')) {
                        $attributes[$key] = ($attributes[$key] ?? 0) + 1;
                        $child->removeAttributeNode($attribute);
                    }
                }

                self::prune($child, $elements, $attributes);
            }
        }
    }

    /**
     * Leaves no text directly in the root or in a `div`: the converter escapes Markdown punctuation at
     * the head of a line only inside a paragraph, and not at all in text whose parent is a `div`.
     */
    private static function paragraphs(DOMNode $container, bool $blockLevel = true): void
    {
        $run = null;

        foreach (iterator_to_array($container->childNodes) as $child) {
            $name = $child instanceof DOMElement ? strtolower($child->nodeName) : null;
            $isBlock = $name !== null && in_array($name, self::BLOCKS, true);

            if ($name !== null && (in_array($name, self::UNWRAPPED, true) || (! $isBlock && $name !== 'a' && $blockLevel && self::holdsBlock($child)))) {
                self::paragraphs($child, $blockLevel);

                if (! $blockLevel) {
                    $child->appendChild($child->ownerDocument->createElement('br'));
                }

                while ($child->firstChild !== null) {
                    $container->insertBefore($child->firstChild, $child);
                }

                $container->removeChild($child);
                $run = null;

                continue;
            }

            if ($name !== null && ! in_array($name, ['p', 'pre', 'hr'], true) && ! preg_match('/^h[1-6]$/', $name)) {
                self::paragraphs($child, blockLevel: false);
            }

            if ($isBlock) {
                $run = null;
            } elseif ($blockLevel) {
                if ($run === null) {
                    $run = $container->ownerDocument->createElement('p');
                    $container->insertBefore($run, $child);
                }

                $run->appendChild($child);
            }
        }
    }

    private static function holdsBlock(DOMElement $element): bool
    {
        foreach ([...self::BLOCKS, ...self::UNWRAPPED] as $name) {
            if ($element->getElementsByTagName($name)->length > 0) {
                return true;
            }
        }

        return false;
    }

    /** Whether the renderer would drop this URL for its scheme; it refuses a few malformed URLs too, which go unreported. */
    private static function isRefusedScheme(string $attribute, string $url): bool
    {
        $allowed = ['href' => 'https?|mailto', 'src' => 'https?'][$attribute] ?? null;

        return $allowed !== null
            && preg_match('#^\s*[a-z][a-z0-9+.-]*:#i', $url) === 1
            && preg_match('#^\s*('.$allowed.'):#i', $url) !== 1;
    }

    private static function collapse(string $text): string
    {
        return trim((string) preg_replace('/[ \t\r\n\f]+/', ' ', $text));
    }
}
