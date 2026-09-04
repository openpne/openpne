<?php

namespace App\Compat;

/**
 * Each entry is the module's OpenPNE 3 `config/view.yml` `stylesheets:` declaration, so a module
 * absent here declared none. `community` is deliberately absent: the communityTopic.css its home
 * loads in OpenPNE 3 is component-driven (the embedded topic and event list partials), so the
 * group home page links it itself.
 */
final class PluginStylesheets
{
    /** @var array<string, string> OpenPNE 3 module => path under public/ */
    private const BY_MODULE = [
        'diary' => 'opDiaryPlugin/css/diary.css',
        'diaryComment' => 'opDiaryPlugin/css/diary.css',
        'communityTopic' => 'opCommunityTopicPlugin/css/communityTopic.css',
        'communityTopicComment' => 'opCommunityTopicPlugin/css/communityTopic.css',
        'communityEvent' => 'opCommunityTopicPlugin/css/communityTopic.css',
        'communityEventComment' => 'opCommunityTopicPlugin/css/communityTopic.css',
        'message' => 'opMessagePlugin/css/message.css',
    ];

    /** The stylesheet path for a Laravel route, or null when its module declares none. */
    public static function forRoute(?string $laravelRoute): ?string
    {
        $module = $laravelRoute === null ? null : RouteParityRegistry::module($laravelRoute);

        return $module === null ? null : self::BY_MODULE[$module] ?? null;
    }
}
