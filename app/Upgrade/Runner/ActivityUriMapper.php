<?php

namespace App\Upgrade\Runner;

/**
 * The `uri` OpenPNE 3's template activities carried (`@diary_show?id=N` and the two
 * opCommunityTopicPlugin routes) onto the OpenPNE 4 URL of the same record — ids copy verbatim, so
 * the id is the same. Null for anything else; a hand-written activity has no link.
 */
final class ActivityUriMapper
{
    public static function resolve(?string $uri): ?string
    {
        if ($uri === null) {
            return null;
        }

        $path = (string) parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $params);
        $id = (string) ($params['id'] ?? '');
        if (! ctype_digit($id)) {
            return null;
        }

        return match ($path) {
            '@diary_show' => route('diary.show', ['diary' => (int) $id]),
            '@communityTopic_show' => route('group.topics.show', ['topic' => (int) $id]),
            '@communityEvent_show' => route('group.events.show', ['event' => (int) $id]),
            default => null,
        };
    }
}
