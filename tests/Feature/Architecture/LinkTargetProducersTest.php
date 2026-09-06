<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Where a link opens is decided by LinkTarget and <BodyLink>, so a `target="_blank"` written anywhere
 * else is either one of the named carve-outs or a link that slipped past the rule
 * (docs/internals/body-text.md, "Where a link opens").
 */
class LinkTargetProducersTest extends TestCase
{
    private const ALLOWED = [
        // The rule itself.
        'app/Support/LinkTarget.php',
        'app/Support/MarkdownText.php',
        'resources/js/components/body-link.tsx',
        'resources/js/components/rich-body.tsx',
        // Carve-outs: a photo's full-size link, the lightbox and the footer keep their new tab.
        'app/Support/SnsSettingKey.php',
        'resources/js/components/lightbox.tsx',
        'resources/views/components/classic/photo-rows.blade.php',
        'resources/views/filament/components/image-lightbox.blade.php',
        'resources/views/group-event/_images.blade.php',
        'resources/views/group-topic/_images.blade.php',
        'resources/views/layouts/classic.blade.php',
        'resources/views/message/show.blade.php',
    ];

    public function test_a_new_tab_is_opened_only_by_the_rule_or_a_named_carve_out(): void
    {
        $finder = (new Finder)
            ->files()
            ->in(array_map(base_path(...), ['app', 'config', 'database', 'lang', 'public', 'resources', 'routes']))
            ->name(['*.php', '*.ts', '*.tsx', '*.js', '*.jsx', '*.json', '*.html'])
            ->notName('*.test.*')
            // Built and published assets under public/ (gitignored), not this app's markup.
            ->exclude(['build', 'storage', 'css/filament', 'fonts/filament', 'js/filament'])
            ->contains('_blank');

        $found = [];

        foreach ($finder as $file) {
            $found[] = str_replace(base_path().'/', '', $file->getRealPath());
        }

        sort($found);
        $allowed = self::ALLOWED;
        sort($allowed);

        $this->assertSame($allowed, $found, 'The files that open a new tab changed. A new one goes through LinkTarget / <BodyLink>, or is named as a carve-out here and in the docs; one that stopped is dropped from the list.');
    }
}
