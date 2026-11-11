<?php

declare(strict_types=1);

namespace App\Support;

use DOMComment;
use DOMElement;
use DOMNode;
use League\HTMLToMarkdown\HtmlConverter;
use Masterminds\HTML5;

/**
 * Rewrites operator HTML as Markdown that MarkdownText::renderOperator() draws the way a browser drew
 * the HTML, minus what Markdown cannot say (docs/internals/body-text.md, "Operator text").
 */
final class OperatorHtmlMarkdown
{
    /** Removed with their content: nothing inside them is text the page showed. */
    private const REMOVED = ['script', 'style', 'iframe', 'noscript', 'meta', 'link', 'base', 'title', 'object', 'embed', 'template', 'svg', 'math'];

    /** Containers whose tag is dropped and whose inline children become paragraphs. */
    private const UNWRAPPED = ['div', 'center', 'section', 'article', 'header', 'footer', 'nav', 'aside', 'main', 'form', 'fieldset'];

    private const BLOCKS = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'ul', 'ol', 'dl', 'table', 'blockquote', 'pre'];

    /** The attributes Markdown carries, by element. */
    private const KEPT_ATTRIBUTES = ['a' => ['href', 'title'], 'img' => ['src', 'alt', 'title']];

    public static function convert(string $html): ConvertedOperatorHtml
    {
        if (trim($html) === '') {
            return new ConvertedOperatorHtml('');
        }

        if (preg_match('~<[a-z!/][^>]*>~i', $html) !== 1) {
            // A browser decoded entities and collapsed whitespace; OpenPNE 3 printed these fields with no nl2br.
            $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return new ConvertedOperatorHtml(MarkdownEscape::plain(self::collapse($text)));
        }

        // An HTML5 parse, so a stray `</br>` or a misnested tag takes the shape a browser gave it.
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
            // `<br>` becomes a bare newline, which MarkdownText renders back as `<br>`; the converter folds every other newline into a space, as the browser did.
            'hard_break' => true,
            'header_style' => 'atx',
            'use_autolinks' => false,
        ]);

        $markdown = $converter->convert($parser->saveHTML($fragment));
        $markdown = trim((string) preg_replace("/\n{3,}/", "\n\n", $markdown));

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

                    if (! in_array($key, self::KEPT_ATTRIBUTES[$name] ?? [], true)) {
                        $attributes[$key] = ($attributes[$key] ?? 0) + 1;
                        $child->removeAttributeNode($attribute);
                    }
                }

                self::prune($child, $elements, $attributes);
            }
        }
    }

    /**
     * The converter escapes Markdown punctuation only for text inside a paragraph, so every inline run
     * that sits directly in the root or in an unwrapped container gets one.
     */
    private static function paragraphs(DOMNode $container): void
    {
        $run = null;

        foreach (iterator_to_array($container->childNodes) as $child) {
            $name = $child instanceof DOMElement ? strtolower($child->nodeName) : null;

            if ($name !== null && in_array($name, self::UNWRAPPED, true)) {
                self::paragraphs($child);

                while ($child->firstChild !== null) {
                    $container->insertBefore($child->firstChild, $child);
                }

                $container->removeChild($child);
                $run = null;
            } elseif ($name !== null && in_array($name, self::BLOCKS, true)) {
                $run = null;
            } else {
                if ($run === null) {
                    $run = $container->ownerDocument->createElement('p');
                    $container->insertBefore($run, $child);
                }

                $run->appendChild($child);
            }
        }
    }

    private static function collapse(string $text): string
    {
        return trim((string) preg_replace('/[ \t\r\n\f]+/', ' ', $text));
    }
}
