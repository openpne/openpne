<?php

namespace Tests\Feature\Frontend;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Guards the member page frame's ownership: MemberFrame renders the single <main> and the central
 * flash for every non-auth Modern page, so a page (or a page-colocated component) must not carry its
 * own <main> or FlashMessage — a leftover would nest a second <main> landmark or double-render
 * flash. Consistency is enforced, not opt-in: a new page cannot bypass the frame unnoticed.
 */
class MemberFrameGuardTest extends TestCase
{
    /** The only files that may render a <main> landmark. */
    private const MAIN_OWNERS = [
        'components/member-frame.tsx',
        'layouts/auth-layout.tsx',
    ];

    /** The only non-auth files that may use FlashMessage (auth pages render their own inside AuthLayout). */
    private const FLASH_OWNERS = [
        'components/member-frame.tsx',
    ];

    /** @return list<string> */
    private function tsxFiles(): array
    {
        $base = resource_path('js');
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.tsx')) {
                $files[] = str_replace($base.'/', '', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    public function test_only_the_frame_and_auth_layout_render_main(): void
    {
        $offenders = [];
        foreach ($this->tsxFiles() as $file) {
            if (in_array($file, self::MAIN_OWNERS, true)) {
                continue;
            }
            $contents = file_get_contents(resource_path('js/'.$file));
            // JSX opening tag at line start; comments mentioning `<main>` are prefixed by // or *.
            if (preg_match('/^\s*<main\b/m', $contents) === 1) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'Pages must not render their own <main> — the member frame owns it.');
    }

    public function test_only_the_frame_renders_flash_outside_auth(): void
    {
        $offenders = [];
        foreach ($this->tsxFiles() as $file) {
            if (in_array($file, self::FLASH_OWNERS, true) || str_starts_with($file, 'pages/auth/')) {
                continue;
            }
            if ($file === 'components/flash-message.tsx') {
                continue;
            }
            $contents = file_get_contents(resource_path('js/'.$file));
            if (str_contains($contents, 'components/flash-message')) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'Pages must not render FlashMessage — the member frame renders flash centrally.');
    }
}
