<?php

namespace App\Compat;

/**
 * The OpenPNE 3 plugin stylesheet a Classic page links, keyed by the OpenPNE 3 module the page
 * renders under: the port of each plugin's `apps/pc_frontend/modules/{module}/config/view.yml`
 * `all: stylesheets:` entry, vendored verbatim under public/.
 *
 * Per module, not global. The files override shared skin rules — diary.css restyles
 * `.commentList` / `.recentList` / `.prevNextLinkLine`, message.css restyles
 * `.prevNextLinkLine` too — so linking them everywhere would change screens OpenPNE 3 left
 * alone. A module absent here declares no stylesheet in OpenPNE 3 (e.g. `community`, whose home
 * embeds topic and event components without loading communityTopic.css).
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
