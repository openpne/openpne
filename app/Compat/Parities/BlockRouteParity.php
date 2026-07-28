<?php

namespace App\Compat\Parities;

use App\Compat\CompatLevel as L;
use App\Compat\RouteMap;
use App\Compat\RouteParity;
use App\Compat\ScreenElement;
use App\Compat\ScreenStatus as S;

/**
 * Block has no OpenPNE 3 module: access block lived as a member-config category
 * (`/member/config?category=accessBlock`), now split into an OpenPNE 4-native Block feature.
 * So every map is OpenPNE 4-native (op3Route null) and the parity binds to no inventory module;
 * the legacy `/member/config` URL is preserved by a redirect declared in routes/web.php.
 */
class BlockRouteParity extends RouteParity
{
    protected string $module = 'block';

    public function openpne3Module(): ?string
    {
        return null;
    }

    public function maps(): array
    {
        return [
            new RouteMap(null, null, 'block.list', 'GET', op3Action: 'list'),
            new RouteMap(null, null, 'block.add.show', 'GET', op3Action: 'add'),
            new RouteMap(null, null, 'block.add', 'POST'),
            new RouteMap(null, null, 'block.remove.show', 'GET', op3Action: 'remove'),
            new RouteMap(null, null, 'block.remove.submit', 'POST'),
        ];
    }

    public function compatRedirects(): array
    {
        // Access block's OpenPNE 3 URL; redirected (not served) to the new canonical Block list.
        return ['/member/config?category=accessBlock' => 'block.list'];
    }

    /**
     * Surface elements against resources/views/block/*.blade.php. The OpenPNE 3 counterpart is a
     * member-config category rather than a module, so the sources are its config entry, its form
     * class, and member/configSuccess.php — plus, for the confirm pages OpenPNE 3 has no
     * counterpart for, the hand-written confirm shape the Classic adapter reuses.
     */
    public function screens(): array
    {
        return [
            // member/configSuccess.php ?category=accessBlock → block/list.blade.php
            'list' => [
                new ScreenElement('blocked-member list', L::Two, S::Partial, 'member_config.yml accessBlock.access_block (FormType increased_input) + opWidgetFormInputIncreased ul > li', 'a ul of names with a per-row Unblock link; OpenPNE 3 listed the blocked member ids as a growing column of text inputs inside the settings form, and cleared a block by emptying its input'),
                new ScreenElement('block-a-member input', L::Two, S::Partial, 'member_config.yml accessBlock.access_block (the trailing empty input)', 'its own GET form leading to a confirm page, not the last row of the settings form'),
                new ScreenElement('member ID help text', L::Three, S::Ported, "MemberConfigAccessBlockForm::configure() setHelp('access_block', 'Block access from the selected member with input MemberID…')"),
                new ScreenElement('category heading', L::Three, S::Partial, 'member_config.yml accessBlock._attributes.caption "Access Block Configuration"', 'two boxes headed for what they do, now that this is a screen of its own rather than a settings category'),
                new ScreenElement('settings-page sidemenu', L::Two, S::Missing, "member/configSuccess.php op_include_parts('pageNav', 'pageNav')", 'the screen stands outside member config, so it inherits no category nav; the legacy URL reaches it through compatRedirects()'),
                new ScreenElement('pager navigation (above and below)', L::Two, S::Ported, '_pagerNavigation.php + _pagerTotal.php (op_include_pager_navigation)', 'x-classic.pager; the OpenPNE 3 category had no pager because one increased_input held every id'),
            ],
            // block/add.blade.php — no OpenPNE 3 counterpart (saving the settings form was the act)
            'add' => [
                new ScreenElement('confirmation box', L::Two, S::Ported, 'diary/deleteConfirmSuccess.php <div class="dparts box"><div class="block">', 'the hand-written OpenPNE 3 confirm shape: .block, deliberately not .body, so the body renders unpadded'),
                new ScreenElement('confirmation question naming the target', L::Two, S::Ported, 'member_config.yml accessBlock.access_block (IsConfirm: false)', 'OpenPNE 3 blocked straight from the settings form, with no confirm step and no name — only the id the member typed'),
                new ScreenElement('submit + Cancel row', L::Two, S::Ported, 'diary/deleteConfirmSuccess.php div.operation > ul.moreInfo.button', 'the Cancel link is the OpenPNE 4 shape every confirm screen uses; OpenPNE 3 confirms carried the submit alone'),
            ],
            // block/remove.blade.php — same, for clearing a block
            'remove' => [
                new ScreenElement('confirmation box', L::Two, S::Ported, 'diary/deleteConfirmSuccess.php <div class="dparts box"><div class="block">', 'the hand-written OpenPNE 3 confirm shape: .block, deliberately not .body, so the body renders unpadded'),
                new ScreenElement('confirmation question naming the target', L::Two, S::Ported, 'member_config.yml accessBlock.access_block (IsConfirm: false)', 'OpenPNE 3 unblocked by emptying the id\'s input and saving, with no confirm step'),
                new ScreenElement('submit + Cancel row', L::Two, S::Ported, 'diary/deleteConfirmSuccess.php div.operation > ul.moreInfo.button', 'the Cancel link is the OpenPNE 4 shape every confirm screen uses; OpenPNE 3 confirms carried the submit alone'),
            ],
        ];
    }
}
