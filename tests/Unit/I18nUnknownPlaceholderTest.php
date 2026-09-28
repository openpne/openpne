<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CheckTranslationsCommand as Cmd;
use App\Services\TermService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class I18nUnknownPlaceholderTest extends TestCase
{
    use RefreshDatabase;

    private const TERMS = ['friend', 'my_friend', 'community', 'diary'];

    /** @param  list<string>  $expected */
    #[DataProvider('strings')]
    public function test_a_placeholder_no_term_answers_to_is_named(string $text, array $expected): void
    {
        $this->assertSame($expected, Cmd::unknownPlaceholders($text, self::TERMS));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function strings(): array
    {
        return [
            'a name that is not a term, among other words' => ['%Group% settings', ['%Group%']],
            'a misspelt term' => ['Add %firend%', ['%firend%']],
            'the same name twice is named once' => ['%Group% and %Group%', ['%Group%']],
            'one known, one not' => ['%Community% of %Groups%', ['%Groups%']],
            'in a ja value' => ['%group%の設定', ['%group%']],
            'a term' => ['%community% settings', []],
            'capitalized' => ['%Community% settings', []],
            'plural' => ['Your %communities%', []],
            'capitalized plural' => ['%Diaries% by %my_friends%', []],
            'a replacement parameter' => [':community has :count members', []],
            'a percent sign' => ['50% of 100%', []],
            'no placeholder' => ['Settings', []],
        ];
    }

    public function test_the_gate_reads_a_name_as_the_term_layer_does(): void
    {
        $terms = app(TermService::class);

        foreach (['%Group% settings', '%Communities% near you', '%my_friend%', '%Firend%'] as $text) {
            $left = $terms->replace($text, 'en') === $text;

            $this->assertSame($left, Cmd::unknownPlaceholders($text, array_keys(TermService::defaults('en'))) !== [], $text);
        }
    }
}
