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
 * CommonMark with html_input=escape, then a symfony/html-sanitizer allowlist: the two layers are
 * deliberately redundant, so a body stays inert if either one regresses (docs/internals/body-text.md).
 */
final class MarkdownText
{
    /** @var array<int, MarkdownConverter> */
    private static array $converters = [];

    /** @var array<int, HtmlSanitizer> */
    private static array $sanitizers = [];

    /**
     * The anchor the sanitizer emits for every link, hardened for another site; retarget() reads this
     * exact shape, so a drift in it leaves every link hardened rather than one of ours opening in place.
     */
    private const SANITIZED_ANCHOR = '~<a href="([^"]*+)" rel="noopener noreferrer nofollow" target="_blank">(.*?)</a>~s';

    public static function render(?string $text): HtmlString
    {
        return new HtmlString(self::retarget(self::sanitized($text)));
    }

    /**
     * Text an administrator wrote: the member pipeline plus images and links relative to this site,
     * which OpenPNE 3 operator HTML carried and its conversion keeps (docs/internals/body-text.md, "Operator text").
     */
    public static function renderOperator(?string $text): HtmlString
    {
        return new HtmlString(self::retarget(self::sanitized($text, operator: true), operator: true));
    }

    /** Before retarget(), so text drawn from it carries no notice. */
    private static function sanitized(?string $text, bool $operator = false): string
    {
        return self::sanitizer($operator)->sanitize(self::converter($operator)->convert((string) $text)->getContent());
    }

    /**
     * Read from the parsed document rather than by matching text, so a URL in a code span or fenced
     * block, which the page does not link, yields no card.
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
        $plain = strip_tags(self::sanitized($text));
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim((string) preg_replace('/\s+/u', ' ', $plain));

        return mb_strimwidth($plain, 0, $width, '');
    }

    /**
     * Block boundaries become newlines so the paragraph and list shape survives, and strip_tags runs
     * before html_entity_decode so a raw-HTML fragment the user typed reads back as they typed it.
     */
    public static function plainText(?string $text): string
    {
        $html = self::sanitized($text);
        // Keep link targets, which strip_tags would drop: a label that is the URL itself stays a single
        // URL, and an unsafe-scheme link has no href after the sanitizer so it keeps its label only.
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

    /** A link to this site loses the new tab the sanitizer forced; one to another site gains the notice. */
    private static function retarget(string $html, bool $operator = false): string
    {
        return preg_replace_callback(self::SANITIZED_ANCHOR, function (array $m) use ($operator): string {
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
            // A URL with no scheme and no host resolves against this page, and a mailto: opens no page at all.
            $target = $operator && preg_match('#^\s*(?!mailto:)([a-z][a-z0-9+.-]*:|//)#i', $url) !== 1
                ? LinkTarget::inPlace()
                : LinkTarget::of($url);

            return '<a href="'.$m[1].'"'.$target->attributes().'>'.$m[2].$target->notice().'</a>';
        }, $html) ?? $html;
    }

    private static function converter(bool $operator = false): MarkdownConverter
    {
        if (isset(self::$converters[(int) $operator])) {
            return self::$converters[(int) $operator];
        }

        $environment = new Environment([
            // Escaped rather than passed through, so the sanitizer is a second belt and not the only defence.
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            // A single newline reads as a line break, so a Markdown body behaves like plain text.
            'renderer' => ['soft_break' => "<br>\n"],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new AutolinkExtension);
        $environment->addExtension(new StrikethroughExtension);
        $environment->addExtension(new TableExtension);

        if ($operator) {
            return self::$converters[1] = new MarkdownConverter($environment);
        }

        // An image renders as its escaped alt text (priority 10 outranks the core ImageRenderer's 0):
        // the sanitizer allowlist has no img, and dropping the tag there would discard the alt text too.
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

        return self::$converters[0] = new MarkdownConverter($environment);
    }

    private static function sanitizer(bool $operator = false): HtmlSanitizer
    {
        if (isset(self::$sanitizers[(int) $operator])) {
            return self::$sanitizers[(int) $operator];
        }

        $config = (new HtmlSanitizerConfig)
            // -1 disables the length cap; the default (20_000 bytes) silently truncates a long body.
            ->withMaxInputLength(-1)
            ->allowLinkSchemes(['http', 'https']);

        foreach (['p', 'br', 'strong', 'em', 'del', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td'] as $element) {
            $config = $config->allowElement($element);
        }

        $config = $config->allowElement('a', ['href'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->forceAttribute('a', 'target', '_blank');

        if ($operator) {
            // The library's default media schemes include data:, so the list is spelled out.
            $config = $config->allowLinkSchemes(['http', 'https', 'mailto'])
                ->allowRelativeLinks()
                ->allowElement('img', ['src', 'alt', 'title'])
                ->allowMediaSchemes(['http', 'https'])
                ->allowRelativeMedias();
        }

        return self::$sanitizers[(int) $operator] = new HtmlSanitizer($config);
    }
}
