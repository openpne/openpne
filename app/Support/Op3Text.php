<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Renders an OpenPNE 3 rich-text body — the <op:*> decoration tags produced by
 * opWidgetFormRichTextareaOpenPNE — as safe HTML, porting that widget's PC-mode
 * (is_use_stylesheet = true) toHtml pipeline. Migrated diary bodies carry these tags;
 * BodyText (the plain path) only strips them.
 *
 * Architecture: tokenize the raw stored body against the OpenPNE 3 master regex (which matches
 * both raw <op:...> and entity &lt;op:...&gt; tag forms), convert op tags to spans, and render
 * the text between them through BodyText — escape, autolink, nl2br — so no utility is duplicated.
 *
 * Deltas from the OpenPNE 3 widget (which itself is XSS-safe only because the template escaped the
 * whole body with ESC_SPECIALCHARS *before* calling toHtml, then toHtml converted the already
 * entity-encoded tags back):
 *  - Text tokens are HTML-escaped here (OpenPNE 3's toHtml did not escape — it relied on the
 *    upstream template escape). Tokenizing the raw body and escaping only the text between tags is
 *    equivalent to OpenPNE 3's escape-then-convert for a raw-stored body, and makes this renderer
 *    XSS-safe on its own. A literal <b>/<script> in the body therefore stays escaped text.
 *  - Consequently quotes and ampersands in text are escaped (ENT_QUOTES, matching ESC_SPECIALCHARS);
 *    the OpenPNE 3 *unit test* fed semi-escaped inputs that left quotes raw, so a case with a quote
 *    in text renders &quot; here where that test showed a bare ".
 *  - A close tag with no open span is dropped (OpenPNE 3 emitted an orphan </span>); dropping keeps
 *    the fragment balanced for injection via dangerouslySetInnerHTML. Unclosed opens are still
 *    auto-closed at the end (OpenPNE 3 htmlTagFollowup).
 */
final class Op3Text
{
    /** OpenPNE 3 master regex, split form: one group capturing a whole <op:*> / &lt;op:*&gt; tag. */
    private const TAG_SPLIT = '/((?:&lt;|<)\/?op:\w+(?:\s+(?:(?!&lt;|<).)*)?(?:&gt;|>))/i';

    /** Parses one tag token into slash / tagname / attribute string (mirrors the master regex groups). */
    private const TAG_PARSE = '/^(?:&lt;|<)(\/?)(op:\w+)(?:\s+((?:(?!&lt;|<).)*))?(?:&gt;|>)$/i';

    /** OpenPNE 3 opColorToHtml / opFontToHtml colour validation: only #RRGGBB survives (else dropped). */
    private const COLOR = '/^#[0-9a-fA-F]{6}$/';

    /** OpenPNE 3 opFontToHtml size → CSS font-size. Anything outside 1..7 is dropped. */
    private const FONT_SIZE_MAP = [
        1 => 'xx-small',
        2 => 'x-small',
        3 => 'small',
        4 => 'medium',
        5 => 'large',
        6 => 'x-large',
        7 => 'xx-large',
    ];

    public static function render(?string $text): HtmlString
    {
        $tokens = preg_split(self::TAG_SPLIT, (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $html = '';
        $open = 0;
        foreach ($tokens as $i => $token) {
            // preg_split with a captured delimiter puts the tags at the odd indices.
            if ($i % 2 === 0) {
                $html .= self::text($token);

                continue;
            }

            preg_match(self::TAG_PARSE, $token, $m);
            if ($m[1] !== '') {
                if ($open > 0) {
                    $html .= '</span>';
                    $open--;
                }

                continue;
            }

            $html .= self::openSpan(strtolower($m[2]), $m[3] ?? '');
            $open++;
        }

        return new HtmlString($html.str_repeat('</span>', $open));
    }

    /** Text between tags: escape, autolink, and line-break via the shared plain renderer. */
    private static function text(string $text): string
    {
        return $text === '' ? '' : BodyText::render($text)->toHtml();
    }

    private static function openSpan(string $tagname, string $attrs): string
    {
        $class = strtr($tagname, ':', '_'); // op:b → op_b; safe because tagname is op:\w+

        if ($tagname === 'op:color') {
            $attributes = self::attributes($attrs);
            $code = $attributes['code'] ?? '';
            $style = preg_match(self::COLOR, $code) ? ' style="color:'.$code.'"' : '';

            return '<span class="'.$class.'"'.$style.'>';
        }

        if ($tagname === 'op:font') {
            $attributes = self::attributes($attrs);
            $style = '';
            $color = $attributes['color'] ?? '';
            if (preg_match(self::COLOR, $color)) {
                $style .= 'color:'.$color.';';
            }
            $size = $attributes['size'] ?? '';
            if (ctype_digit($size) && isset(self::FONT_SIZE_MAP[(int) $size])) {
                $style .= 'font-size:'.self::FONT_SIZE_MAP[(int) $size];
            }

            // OpenPNE 3 always emits the style attribute for op:font, even when empty.
            return '<span class="op_font" style="'.$style.'">';
        }

        return '<span class="'.$class.'">';
    }

    /**
     * OpenPNE 3 getHtmlAttribute: key=value pairs where the value is delimited by a raw " or a
     * &quot;. Later duplicates win, matching OpenPNE 3's array build.
     *
     * @return array<string, string>
     */
    private static function attributes(string $attrs): array
    {
        preg_match_all('/([^\s]*?)=(?:&quot;|")(.*?)(?:&quot;|")/', $attrs, $matches, PREG_SET_ORDER);

        $result = [];
        foreach ($matches as $match) {
            $result[$match[1]] = $match[2];
        }

        return $result;
    }
}
