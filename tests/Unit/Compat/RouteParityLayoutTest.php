<?php

namespace Tests\Unit\Compat;

use App\Compat\Parities\DiaryRouteParity;
use App\Compat\Parities\MessageRouteParity;
use App\Compat\RouteParityRegistry;
use App\Support\SurfaceResolver;
use PHPUnit\Framework\TestCase;

class RouteParityLayoutTest extends TestCase
{
    public function test_diary_member_scoped_screens_are_layout_b(): void
    {
        $parity = new DiaryRouteParity;

        // OpenPNE 3 decorate_with('layoutB'): the author/calendar sidemenu screens.
        $this->assertSame('B', $parity->layout('diary.show'));
        $this->assertSame('B', $parity->layout('diary.new'));
        $this->assertSame('B', $parity->layout('diary.edit'));
        $this->assertSame('B', $parity->layout('diary.list_member'));
        $this->assertSame('B', $parity->layout('diary.list_member.archive'));
    }

    public function test_diary_all_and_friend_lists_keep_the_default_layout(): void
    {
        $parity = new DiaryRouteParity;

        // OpenPNE 3 left these on the global default layoutC (the feed view, no sidemenu).
        $this->assertNull($parity->layout('diary.list'));
        $this->assertNull($parity->layout('diary.list_friend'));
        $this->assertNull($parity->layout('diary.search'));
        $this->assertNull($parity->layout('diary.delete.show'));
    }

    public function test_message_boxes_and_show_are_layout_b(): void
    {
        $parity = new MessageRouteParity;

        foreach ([
            'message.receive', 'message.send', 'message.draft', 'message.trash',
            'message.receive.show', 'message.send.show', 'message.trash.show',
        ] as $route) {
            $this->assertSame('B', $parity->layout($route), $route);
        }

        // Compose/edit forms and the purge confirmation keep the default (no sidemenu).
        $this->assertNull($parity->layout('message.compose'));
        $this->assertNull($parity->layout('message.trash.purge.confirm'));
    }

    public function test_registry_resolves_across_parities_and_defaults_to_null(): void
    {
        $this->assertSame('B', RouteParityRegistry::layout('diary.show'));
        $this->assertSame('B', RouteParityRegistry::layout('message.receive'));
        $this->assertSame('B', RouteParityRegistry::layout('member.config')); // category pageNav sidemenu
        $this->assertSame('A', RouteParityRegistry::layout('community.show')); // home: top + sidemenu
        // A screen with no non-default entry resolves to null; the shell falls back to layoutC.
        $this->assertNull(RouteParityRegistry::layout('friend.list'));
        $this->assertNull(RouteParityRegistry::layout('community.edit'));
    }

    public function test_modern_route_resolves_via_its_canonical_form(): void
    {
        $this->assertSame(
            'B',
            RouteParityRegistry::layout(SurfaceResolver::canonicalName('diary.modern.list_member')),
        );
    }

    public function test_two_column_classic_views_are_a_locked_set(): void
    {
        $views = dirname(__DIR__, 3).'/resources/views';
        $found = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($views, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (! str_contains($src, "@extends('layouts.classic')") || str_contains($src, 'gadget-sections')) {
                continue; // non-Classic, or a gadget page that passes $layout itself
            }
            if (str_contains($src, "@section('sidemenu')") || str_contains($src, "@section('top')")) {
                $found[] = str_replace([$views.'/', '.blade.php'], '', $file->getPathname());
            }
        }
        sort($found);

        // A Classic screen that opens a sidemenu/top column needs an A/B letter (the skin floats
        // #Left only under A/B). Adding one here is the prompt to declare its layout in the
        // matching RouteParity::layouts(); otherwise it renders under the default LayoutC and breaks.
        $this->assertSame([
            'community/show', 'diary/edit', 'diary/list', 'diary/new', 'diary/show',
            'member/config', 'message/list', 'message/show',
        ], $found);
    }
}
