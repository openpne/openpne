<?php

namespace Tests\Feature\Frontend;

use Tests\TestCase;

/**
 * Guards the compose sheet's action contract: below lg a compose page has no in-page submit button —
 * the sheet header carries it — so a page that forgets the ComposeSheetAction portal or the shared
 * form id ships a screen nobody can submit from a phone.
 */
class ComposeSheetActionGuardTest extends TestCase
{
    /** The compose pages, the sibling of COMPOSE_SCREENS in resources/js/lib/member-chrome.test.ts. */
    private const COMPOSE_PAGES = [
        'pages/diary/new.tsx',
        'pages/diary/edit.tsx',
        'pages/timeline/new.tsx',
        'pages/community/topic/edit.tsx',
        'pages/community/event/edit.tsx',
        'pages/message/compose.tsx',
        'pages/message/edit.tsx',
    ];

    public function test_every_compose_page_renders_its_actions_in_the_sheet_header(): void
    {
        $offenders = [];
        foreach (self::COMPOSE_PAGES as $page) {
            // The JSX usage, not the import: importing the component submits nothing.
            if (! str_contains($this->source($page), '<ComposeSheetAction')) {
                $offenders[] = $page;
            }
        }

        $this->assertSame([], $offenders, 'Compose page(s) with no <ComposeSheetAction> — their actions never reach the sheet header.');
    }

    public function test_every_compose_form_carries_the_shared_id(): void
    {
        $offenders = [];
        foreach (self::COMPOSE_PAGES as $page) {
            if (preg_match('/<form\b[^>]*\bid=\{COMPOSE_FORM_ID\}/s', $this->source($page)) !== 1) {
                $offenders[] = $page;
            }
        }

        $this->assertSame([], $offenders, 'Compose page(s) whose <form> is missing id={COMPOSE_FORM_ID} — the header button cannot submit it.');
    }

    private function source(string $page): string
    {
        return (string) file_get_contents(resource_path('js/'.$page));
    }
}
