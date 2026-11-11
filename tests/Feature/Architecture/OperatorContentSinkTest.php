<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class OperatorContentSinkTest extends TestCase
{
    /** A view printing a stored operator value itself would bypass the mode switch in App\Support\OperatorContent. */
    public function test_no_view_prints_stored_operator_content_unescaped_itself(): void
    {
        $offenders = [];

        foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            preg_match_all('/\{!!(.*?)!!\}/s', $file->getContents(), $matches);

            foreach ($matches[1] as $expression) {
                if (str_contains($expression, 'OperatorContent::render(')) {
                    continue;
                }
                if (preg_match('/\$config\[|->html\b|->markdown\b|SnsSettingService|SnsSettingKey/', $expression) === 1) {
                    $offenders[] = $file->getRelativePathname().': '.trim($expression);
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_only_the_sink_reads_the_raw_html_switch(): void
    {
        $readers = [];

        foreach (Finder::create()->files()->in([app_path(), resource_path('views')])->name('*.php') as $file) {
            if (str_contains($file->getContents(), 'operator_raw_html')) {
                $readers[] = $file->getRelativePathname();
            }
        }

        $this->assertSame(['Support/OperatorContent.php'], $readers);
    }
}
