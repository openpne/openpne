<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Pins docs/internals/security.md, "Operator script policy": under the policy a script without the
 * nonce and an inline event handler do not run, so the Classic views may ship neither.
 */
class ClassicScriptNonceTest extends TestCase
{
    public function test_every_executable_script_tag_takes_the_nonce_and_no_view_has_an_inline_handler(): void
    {
        $checked = 0;
        foreach (File::allFiles(resource_path('views')) as $file) {
            $path = $file->getRelativePathname();
            // The Modern shell and the panel are outside the policy; mail bodies are not pages.
            if ($path === 'app.blade.php' || str_starts_with($path, 'filament/') || str_starts_with($path, 'mail/')) {
                continue;
            }

            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());

            preg_match_all('~<script\b.*?</script>~s', $source, $tags);
            foreach ($tags[0] as $tag) {
                $checked++;
                $this->assertTrue(
                    str_contains($tag, '@scriptNonce') || str_contains($tag, 'type="application/json"'),
                    "{$path}: {$tag} needs @scriptNonce",
                );
            }

            $this->assertSame(0, preg_match('/<[a-zA-Z][^<>]*\son[a-z]+\s*=/', $source), "{$path} has an inline event handler");
        }

        $this->assertGreaterThan(10, $checked);
    }
}
