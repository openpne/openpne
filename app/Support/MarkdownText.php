<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\NodeIterator;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\Xml;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Renders a user-entered Markdown body as safe HTML, an opt-in alternative to the plain path
 * (BodyText). CommonMark + a GitHub-flavoured subset (autolink, strikethrough, tables) produces the
 * HTML; a symfony/html-sanitizer allowlist is the second belt over it. The two layers are
 * independent: CommonMark escapes raw HTML input (html_input=escape) and the sanitizer strips
 * anything outside the allowlist, so a body is safe even if one layer ever regresses.
 */
final class MarkdownText
{
    private static ?MarkdownConverter $converter = null;

    private static ?HtmlSanitizer $sanitizer = null;

    public static function render(?string $text): HtmlString
    {
        $html = self::converter()->convert((string) $text)->getContent();

        return new HtmlString(self::sanitizer()->sanitize($html));
    }

    /**
     * The URLs this body links to, in document order.
     *
     * Read from the parsed document rather than by matching text, so it sees exactly what the
     * renderer will link: `[label](url)` and a bare URL (which the autolink extension turns into the
     * same node) both count, while a URL inside a code span or fenced block does not — it is not a
     * link on the page either, and a card for it would be surprising.
     *
     * @return list<string>
     */
    public static function urls(?string $text): array
    {
        $parser = new MarkdownParser(self::converter()->getEnvironment());
        $urls = [];

        foreach (new NodeIterator($parser->parse((string) $text)) as $node) {
            if ($node instanceof Link) {
                $urls[] = $node->getUrl();
            }
        }

        return $urls;
    }

    /**
     * A feed excerpt of a Markdown body: the rendered HTML flattened to plain text, cut to the same
     * display width as the plain path (BodyText::excerpt) with no ellipsis. strip_tags runs BEFORE
     * html_entity_decode so a raw-HTML fragment the user typed — which CommonMark escaped to entities,
     * not tags — reads back as they typed it (`<b>x</b>`) rather than being stripped as a tag.
     */
    public static function excerpt(?string $text, int $width = BodyText::EXCERPT_WIDTH): string
    {
        $plain = strip_tags(self::render($text)->toHtml());
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim((string) preg_replace('/\s+/u', ' ', $plain));

        return mb_strimwidth($plain, 0, $width, '');
    }

    /**
     * The full body flattened to plain text for a text/plain context (notification mail), with no
     * width cut. Renders to HTML, turns <br> and block-element boundaries into newlines so the
     * paragraph/list shape survives, then strips tags and decodes entities (strip_tags before decode,
     * as in excerpt(), so a raw-HTML fragment the user typed reads back as they typed it). Runs of
     * three-plus newlines collapse to a blank line.
     */
    public static function plainText(?string $text): string
    {
        $html = self::render($text)->toHtml();
        // Keep link targets: strip_tags would reduce [label](url) to just the label, silently
        // dropping the reference from a text/plain mail. A label that is the URL itself (autolink)
        // stays a single URL; an unsafe-scheme link has no href after the sanitizer and keeps its
        // label only (the regex does not match).
        $html = (string) preg_replace_callback(
            '~<a\b[^>]*\bhref="([^"]*)"[^>]*>(.*?)</a>~is',
            function (array $m): string {
                $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($label === $href || "http://{$label}" === $href || "https://{$label}" === $href) {
                    return $m[2];
                }

                // Re-escape: this value flows through the shared strip_tags + entity-decode below.
                return $m[2].' ('.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').')';
            },
            $html,
        );
        // Strip only the newlines adjacent to <br> / a block-end tag (CommonMark's cosmetic ones);
        // newlines inside a <pre> block are content and must survive.
        $html = (string) preg_replace('~<br\s*/?>\s*~i', "\n", $html);
        $html = (string) preg_replace('~</p>\s*~i', "\n\n", $html);
        $html = (string) preg_replace('~</(?:li|h[1-6]|blockquote|tr|pre|ul|ol|table)>\s*~i', "\n", $html);

        $plain = strip_tags($html);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = (string) preg_replace("/\n{3,}/", "\n\n", $plain);

        return trim($plain);
    }

    private static function converter(): MarkdownConverter
    {
        if (self::$converter !== null) {
            return self::$converter;
        }

        $environment = new Environment([
            // Escape raw HTML rather than pass it through; the sanitizer is a second belt, not the
            // only defence. Single newlines become <br> so a Markdown body reads plaintext-like.
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'renderer' => ['soft_break' => "<br>\n"],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new AutolinkExtension);
        $environment->addExtension(new StrikethroughExtension);
        $environment->addExtension(new TableExtension);

        // Render an image as its escaped alt text, not <img>: the sanitizer allowlist has no img, so
        // letting the tag through only to drop it would silently discard the alt text too. Priority
        // above the core ImageRenderer (0) so this wins.
        $environment->addRenderer(Image::class, new class implements NodeRendererInterface
        {
            public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
            {
                Image::assertInstanceOf($node);

                $alt = '';
                foreach (new NodeIterator($node) as $descendant) {
                    if ($descendant instanceof StringContainerInterface) {
                        $alt .= $descendant->getLiteral();
                    } elseif ($descendant instanceof Newline) {
                        $alt .= "\n";
                    }
                }

                return Xml::escape($alt);
            }
        }, 10);

        return self::$converter = new MarkdownConverter($environment);
    }

    private static function sanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer !== null) {
            return self::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            // -1 disables the length cap; the default (20_000 bytes) silently truncates a long body.
            ->withMaxInputLength(-1)
            ->allowLinkSchemes(['http', 'https']);

        foreach (['p', 'br', 'strong', 'em', 'del', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td'] as $element) {
            $config = $config->allowElement($element);
        }

        // Links carry href only; no title/class/style. Every link opens in a new tab with a hardened rel.
        $config = $config->allowElement('a', ['href'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->forceAttribute('a', 'target', '_blank');

        return self::$sanitizer = new HtmlSanitizer($config);
    }
}
