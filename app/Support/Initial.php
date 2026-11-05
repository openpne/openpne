<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The server twin of resources/js/lib/identity-mark.ts computeInitial: the two must agree, in code
 * points, so the generated favicon and the Modern brand mark show the same letters.
 */
final class Initial
{
    private const CJK = '/^[\x{3000}-\x{303F}\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}]$/u';

    /** The characters JavaScript's trim() and \s cover, spelled out: PCRE's \s has U+0085 and lacks U+FEFF. */
    private const SPACE = '[\t\n\x0B\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    public static function of(string $name): string
    {
        $trimmed = (string) preg_replace('/^'.self::SPACE.'+|'.self::SPACE.'+$/u', '', $name);

        if ($trimmed === '') {
            return '??';
        }

        $first = mb_substr($trimmed, 0, 1);

        if (preg_match(self::CJK, $first) === 1) {
            return $first;
        }

        $firstWord = preg_split('/'.self::SPACE.'+/u', $trimmed)[0] ?? '';

        return mb_strtoupper(mb_substr($firstWord, 0, 2));
    }
}
