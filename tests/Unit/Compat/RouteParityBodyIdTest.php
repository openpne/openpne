<?php

namespace Tests\Unit\Compat;

use App\Compat\Parities\DiaryRouteParity;
use PHPUnit\Framework\TestCase;

class RouteParityBodyIdTest extends TestCase
{
    public function test_derives_openpne3_faithful_body_ids(): void
    {
        $parity = new DiaryRouteParity;

        // page_{module}_{action} keyed on the OpenPNE 3 action, not the Laravel route name.
        $this->assertSame('page_diary_show', $parity->bodyId('diary.show'));
        $this->assertSame('page_diary_new', $parity->bodyId('diary.new'));
        $this->assertSame('page_diary_edit', $parity->bodyId('diary.edit'));
        // The two values OpenPNE 4 previously hand-wrote wrong.
        $this->assertSame('page_diary_listMember', $parity->bodyId('diary.list_member'));
        $this->assertSame('page_diary_deleteConfirm', $parity->bodyId('diary.delete.show'));
    }

    public function test_post_routes_have_no_body_id(): void
    {
        $parity = new DiaryRouteParity;

        // Form submits render no <body>, so they derive no body id.
        $this->assertNull($parity->bodyId('diary.store'));
        $this->assertNull($parity->bodyId('diary.update'));
        $this->assertNull($parity->bodyId('diary.delete'));
    }

    public function test_unknown_route_has_no_body_id(): void
    {
        $this->assertNull((new DiaryRouteParity)->bodyId('diary.nonexistent'));
    }
}
