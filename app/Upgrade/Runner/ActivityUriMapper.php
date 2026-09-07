<?php

namespace App\Upgrade\Runner;

/**
 * The `uri` OpenPNE 3's template activities carried (`@diary_show?id=N` and the two
 * opCommunityTopicPlugin routes) onto the OpenPNE 4 URL of the same record — ids copy verbatim, so
 * the id is the same. Null for anything else, never an exception: the source column is free text.
 */
final class ActivityUriMapper
{
    /** @param  string  $route  the OpenPNE 3 route the template's uri must name (ActivityTemplateRenderer::TEMPLATES) */
    public static function resolve(?string $uri, string $route): ?string
    {
        if ($uri === null || parse_url($uri, PHP_URL_PATH) !== $route) {
            return null;
        }

        parse_str((string) parse_url($uri, PHP_URL_QUERY), $params);
        $id = $params['id'] ?? null;
        if (! is_string($id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return null;
        }

        return match ($route) {
            '@diary_show' => route('diary.show', ['diary' => (int) $id]),
            '@communityTopic_show' => route('group.topics.show', ['topic' => (int) $id]),
            '@communityEvent_show' => route('group.events.show', ['event' => (int) $id]),
        };
    }
}
