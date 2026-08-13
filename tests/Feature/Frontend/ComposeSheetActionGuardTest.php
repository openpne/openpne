<?php

namespace Tests\Feature\Frontend;

use Tests\TestCase;

/**
 * Guards the compose sheet's action contract: below lg a compose page has no in-page submit button —
 * the sheet header carries it — so a page that forgets the ComposeSheetAction portal, or a native
 * submitter that loses its form pairing, ships a screen nobody can submit from a phone.
 */
class ComposeSheetActionGuardTest extends TestCase
{
    /**
     * Compose pages whose header action is a native external submitter
     * (`type="submit" form={COMPOSE_FORM_ID}`), so constraint validation still runs.
     * With the click-path pages below, the sibling of COMPOSE_SCREENS in
     * resources/js/lib/member-chrome.test.ts.
     */
    private const NATIVE_SUBMIT_PAGES = [
        'pages/diary/new.tsx',
        'pages/diary/edit.tsx',
        'pages/timeline/new.tsx',
        'pages/group/topic/edit.tsx',
        'pages/community/event/edit.tsx',
    ];

    /** Compose pages whose header actions post from their own click handlers (send/draft pair). */
    private const CLICK_SUBMIT_PAGES = [
        'pages/message/compose.tsx',
        'pages/message/edit.tsx',
    ];

    public function test_every_compose_page_renders_its_actions_in_the_sheet_header(): void
    {
        $offenders = [];
        foreach ([...self::NATIVE_SUBMIT_PAGES, ...self::CLICK_SUBMIT_PAGES] as $page) {
            // The JSX usage, not the import: importing the component submits nothing.
            if (! str_contains($this->source($page), '<ComposeSheetAction')) {
                $offenders[] = $page;
            }
        }

        $this->assertSame([], $offenders, 'Compose page(s) with no <ComposeSheetAction> — their actions never reach the sheet header.');
    }

    public function test_every_native_submit_page_pairs_the_form_id_with_an_external_submitter(): void
    {
        $missingId = [];
        $missingSubmitter = [];
        foreach (self::NATIVE_SUBMIT_PAGES as $page) {
            $source = $this->source($page);
            if (preg_match('/<form\b[^>]*\bid=\{COMPOSE_FORM_ID\}/s', $source) !== 1) {
                $missingId[] = $page;
            }
            // Leading whitespace keeps this from matching the <form … id={…}> tag itself.
            if (preg_match('/\sform=\{COMPOSE_FORM_ID\}/', $source) !== 1) {
                $missingSubmitter[] = $page;
            }
        }

        $this->assertSame([], $missingId, 'Page(s) whose <form> is missing id={COMPOSE_FORM_ID} — the header submitter has nothing to submit.');
        $this->assertSame([], $missingSubmitter, 'Page(s) with no form={COMPOSE_FORM_ID} action — the header button no longer submits the form.');
    }

    private function source(string $page): string
    {
        return (string) file_get_contents(resource_path('js/'.$page));
    }
}
