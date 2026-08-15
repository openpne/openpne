<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * <x-classic.ai-mark> is what Classic has in place of Modern's AiChip. Two rules are load-bearing:
 * a human's name comes back byte-for-byte as it was (Classic markup is what customer CSS targets),
 * and the mark brings its own separator, so no call site has to leave a stray space behind for the
 * human case.
 */
class ClassicAiMarkComponentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('en');
    }

    public function test_a_human_name_is_left_exactly_as_it_was(): void
    {
        $this->assertSame('Kaoru', $this->render('Kaoru', false));
    }

    public function test_an_ai_account_is_marked_after_the_name_it_belongs_to(): void
    {
        $this->assertSame('Shirabe&nbsp;<span class="aiMark">(AI)</span>', $this->render('Shirabe', true));
    }

    /** A name with the mark appended, as a call site writes it: no separator of its own. */
    private function render(string $name, bool $isAi): string
    {
        return Blade::render(
            '{{ $name }}<x-classic.ai-mark :is-ai="$isAi" />',
            ['name' => $name, 'isAi' => $isAi],
        );
    }
}
