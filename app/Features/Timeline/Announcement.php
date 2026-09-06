<?php

namespace App\Features\Timeline;

use App\Upgrade\Runner\EmojiMap;

/**
 * The body of an announcement post: one line about the thing created, then its URL, fitted into
 * the OpenPNE 3 activity length. The template is translated (terms included) before the member's
 * own words go in, so a title spelling "%Diary%" is not read as a placeholder (docs/internals/timeline.md, "Automatic posts").
 */
final class Announcement
{
    /** OpenPNE 3 activity_data.body is string(140); timeline_posts.body keeps that cap. */
    public const MAX = 140;

    public function diary(string $title, string $url, string $locale, int $max = self::MAX): ?string
    {
        return $this->compose($this->line('[%Diary%] :title', ['title' => $title], $locale), $url, $max);
    }

    public function topic(string $group, string $name, string $url, string $locale, int $max = self::MAX): ?string
    {
        return $this->compose($this->line('[%Community% %topic%] :name (:group)', ['name' => $name, 'group' => $group], $locale), $url, $max);
    }

    public function event(string $group, string $name, string $open, string $url, string $locale, int $max = self::MAX): ?string
    {
        $line = $open === ''
            ? $this->line('[%Community% event] :name (:group)', ['name' => $name, 'group' => $group], $locale)
            : $this->line('[%Community% event] :name (:group, :open)', ['name' => $name, 'group' => $group, 'open' => $open], $locale);

        return $this->compose($line, $url, $max);
    }

    /**
     * Line, newline, URL within $max code points: the line gives way first and the URL is never cut.
     * Null when the URL alone does not fit, since a line that drops it would announce nothing.
     */
    public function compose(string $line, string $url, int $max = self::MAX): ?string
    {
        $urlLength = mb_strlen($url);
        if ($urlLength > $max) {
            return null;
        }

        $budget = $max - $urlLength - 1;
        if ($budget <= 0) {
            return $url;
        }

        if (mb_strlen($line) > $budget) {
            $line = rtrim(mb_substr($line, 0, $budget - 1)).'…';
        }

        return $line."\n".$url;
    }

    /** @param  array<string, string>  $params */
    private function line(string $key, array $params, string $locale): string
    {
        $template = __($key, [], $locale); // TermTranslator: the %term% placeholders are resolved here

        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements[':'.$name] = EmojiMap::convert($value);
        }

        return strtr($template, $replacements);
    }
}
