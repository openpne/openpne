<?php

namespace Tests\Unit\Compat;

use App\Compat\Parities\BlockRouteParity;
use App\Compat\Parities\DiaryRouteParity;
use App\Compat\Parities\FriendRouteParity;
use App\Compat\Parities\MemberRouteParity;
use App\Support\SurfaceResolver;
use PHPUnit\Framework\TestCase;

class RouteParityBodyIdTest extends TestCase
{
    public function test_derives_openpne3_faithful_body_ids(): void
    {
        $parity = new DiaryRouteParity;

        // page_{module}_{action} keyed on the OpenPNE 3 action, not the Laravel route name.
        $this->assertSame('page_diary_show', $parity->bodyId('diary.show'));
        $this->assertSame('page_diary_search', $parity->bodyId('diary.search'));
        $this->assertSame('page_diary_list', $parity->bodyId('diary.list'));
        $this->assertSame('page_diary_listFriend', $parity->bodyId('diary.list_friend'));
        $this->assertSame('page_diary_new', $parity->bodyId('diary.new'));
        $this->assertSame('page_diary_edit', $parity->bodyId('diary.edit'));
        // The two values OpenPNE 4 previously hand-wrote wrong.
        $this->assertSame('page_diary_listMember', $parity->bodyId('diary.list_member'));
        // The calendar archive is the same listMember action, so it keeps that body id.
        $this->assertSame('page_diary_listMember', $parity->bodyId('diary.list_member.archive'));
        $this->assertSame('page_diary_deleteConfirm', $parity->bodyId('diary.delete.show'));
        // The comment confirm page renders in the diaryComment module (op3Module override),
        // not diary, so its body id must say diaryComment.
        $this->assertSame('page_diaryComment_deleteConfirm', $parity->bodyId('diary.comment.delete.show'));
    }

    public function test_post_routes_have_no_body_id(): void
    {
        $parity = new DiaryRouteParity;

        // Form submits render no <body>, so they derive no body id.
        $this->assertNull($parity->bodyId('diary.store'));
        $this->assertNull($parity->bodyId('diary.update'));
        $this->assertNull($parity->bodyId('diary.delete'));
        $this->assertNull($parity->bodyId('diary.comment.store'));
        $this->assertNull($parity->bodyId('diary.comment.delete'));
    }

    public function test_unknown_route_has_no_body_id(): void
    {
        $this->assertNull((new DiaryRouteParity)->bodyId('diary.nonexistent'));
    }

    public function test_derives_friend_body_ids_including_the_fallback_only_link_route(): void
    {
        $parity = new FriendRouteParity;

        $this->assertSame('page_friend_list', $parity->bodyId('friend.list'));
        $this->assertSame('page_friend_manage', $parity->bodyId('friend.manage'));
        $this->assertSame('page_friend_unlink', $parity->bodyId('friend.unlink.show'));
        // friend.link.show has no named OpenPNE 3 route (fallback-reached) but still carries
        // the action, so its body id stays page_friend_link.
        $this->assertSame('page_friend_link', $parity->bodyId('friend.link.show'));
    }

    public function test_friend_post_submits_have_no_body_id(): void
    {
        $this->assertNull((new FriendRouteParity)->bodyId('friend.unlink.submit'));
    }

    public function test_derives_block_body_ids_for_the_native_feature(): void
    {
        // Block has no OpenPNE 3 module; the body id is still page_{module}_{action} keyed on
        // the OpenPNE 4 action, the hook a theme would target on the new Classic page.
        $parity = new BlockRouteParity;

        $this->assertSame('page_block_list', $parity->bodyId('block.list'));
        $this->assertSame('page_block_add', $parity->bodyId('block.add.show'));
        $this->assertSame('page_block_remove', $parity->bodyId('block.remove.show'));
        // Form submits render no <body>.
        $this->assertNull($parity->bodyId('block.add'));
        $this->assertNull($parity->bodyId('block.remove.submit'));
    }

    public function test_derives_member_body_ids_keyed_on_the_openpne3_action(): void
    {
        // The canonical Laravel routes keep the OpenPNE 3 action, so the body id stays
        // page_member_{action} even where the URL moved (avatar editor, edit profile).
        $parity = new MemberRouteParity;

        // The portal home (/) and its /member alias both carry the OpenPNE 3 member/home action.
        $this->assertSame('page_member_home', $parity->bodyId('home'));
        $this->assertSame('page_member_home', $parity->bodyId('member.index_compat'));
        $this->assertSame('page_member_profile', $parity->bodyId('member.profile.show'));
        $this->assertSame('page_member_configImage', $parity->bodyId('member.avatar.edit'));
        $this->assertSame('page_member_search', $parity->bodyId('member.search'));
        $this->assertSame('page_member_editProfile', $parity->bodyId('member.profile.edit'));
        // Form submits render no <body>.
        $this->assertNull($parity->bodyId('member.avatar.update'));
        $this->assertNull($parity->bodyId('member.profile.update'));
    }

    public function test_modern_route_name_resolves_via_canonical_form(): void
    {
        // A /m/* route that falls back to Classic carries the modern name; canonicalizing it
        // (diary.modern.* -> diary.*) lets the parity derive the same OpenPNE 3 body id.
        $parity = new DiaryRouteParity;

        $this->assertNull($parity->bodyId('diary.modern.list_member'));
        $this->assertSame(
            'page_diary_listMember',
            $parity->bodyId(SurfaceResolver::canonicalName('diary.modern.list_member')),
        );
    }
}
