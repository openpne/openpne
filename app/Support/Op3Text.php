<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * OpenPNE 3 opWidgetFormRichTextareaOpenPNE toHtml, PC mode: <op:*> tags become spans and only the
 * text between them goes through BodyText. Deltas from OpenPNE 3 are listed in docs/internals/body-text.md.
 */
final class Op3Text
{
    /** OpenPNE 3 master regex, split form: one group capturing a whole <op:*> / &lt;op:*&gt; tag. */
    private const TAG_SPLIT = '/((?:&lt;|<)\/?op:\w+(?:\s+(?:(?!&lt;|<).)*)?(?:&gt;|>))/i';

    /** Parses one tag token into slash / tagname / attribute string (mirrors the master regex groups). */
    private const TAG_PARSE = '/^(?:&lt;|<)(\/?)(op:\w+)(?:\s+((?:(?!&lt;|<).)*))?(?:&gt;|>)$/i';

    /** OpenPNE 3 opColorToHtml / opFontToHtml color validation: only #RRGGBB survives (else dropped). */
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
