<?php

declare(strict_types=1);

namespace Tests\Unit\Features\Home;

use App\Features\Home\HomeIssueSection;
use App\Support\Feature;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The section rules, pinned because three different readers depend on them agreeing: the publisher
 * choosing rows, the ledger deciding whether to remember them, and the page re-gating them.
 */
class HomeIssueSectionTest extends TestCase
{
    public function test_each_section_caps_its_own_band(): void
    {
        $this->assertSame(8, HomeIssueSection::Stories->cap());
        $this->assertSame(3, HomeIssueSection::Talk->cap());
        $this->assertSame(12, HomeIssueSection::Newcomers->cap());
        $this->assertSame(6, HomeIssueSection::NewGroups->cap());
        $this->assertSame(6, HomeIssueSection::UpcomingEvents->cap());
    }

    public function test_only_the_window_and_calendar_sections_may_repeat_a_source(): void
    {
        $this->assertTrue(HomeIssueSection::Talk->recurs());
        $this->assertTrue(HomeIssueSection::UpcomingEvents->recurs());

        $this->assertFalse(HomeIssueSection::Stories->recurs());
        $this->assertFalse(HomeIssueSection::Newcomers->recurs());
        $this->assertFalse(HomeIssueSection::NewGroups->recurs());
    }

    public function test_never_again_is_exactly_the_sections_that_do_not_recur(): void
    {
        // A partition, not a list: a section added without a recurs() answer must land on one side
        // or the other, and this fails rather than quietly dropping it from both.
        $neverAgain = HomeIssueSection::neverAgain();
        $recurring = array_values(array_filter(HomeIssueSection::cases(), fn (HomeIssueSection $s): bool => $s->recurs()));

        $this->assertSame(
            count(HomeIssueSection::cases()),
            count($neverAgain) + count($recurring),
        );
        $this->assertSame([], array_intersect(
            array_column($neverAgain, 'value'),
            array_column($recurring, 'value'),
        ));
        $this->assertSame(
            [HomeIssueSection::Stories, HomeIssueSection::Newcomers, HomeIssueSection::NewGroups],
            $neverAgain,
        );
    }

    /** @return array<string, array{0: HomeIssueSection, 1: string, 2: Feature|null}> */
    public static function units(): array
    {
        return [
            'a post in stories' => [HomeIssueSection::Stories, 'timelinePost', Feature::Timeline],
            'a diary in stories' => [HomeIssueSection::Stories, 'diary', Feature::Diary],
            'a topic in stories' => [HomeIssueSection::Stories, 'groupTopic', Feature::GroupTopic],
            'an event in stories' => [HomeIssueSection::Stories, 'groupEvent', Feature::GroupEvent],
            // The same group, gated by two different units depending on why it is here.
            'a group in talk' => [HomeIssueSection::Talk, 'group', Feature::GroupTalk],
            'a group in new groups' => [HomeIssueSection::NewGroups, 'group', Feature::Group],
            'an event on the calendar' => [HomeIssueSection::UpcomingEvents, 'groupEvent', Feature::GroupEvent],
            'a member among newcomers' => [HomeIssueSection::Newcomers, 'member', null],
        ];
    }

    #[DataProvider('units')]
    public function test_a_pair_resolves_to_the_unit_that_gates_it(HomeIssueSection $section, string $alias, ?Feature $unit): void
    {
        $this->assertSame($unit, $section->unit($alias));
        $this->assertTrue($section->allowsSource($alias));
    }

    public function test_a_section_rejects_an_alias_it_does_not_hold(): void
    {
        $this->assertFalse(HomeIssueSection::Newcomers->allowsSource('diary'));

        $this->expectException(InvalidArgumentException::class);
        HomeIssueSection::Newcomers->unit('diary');
    }

    public function test_a_section_rejects_an_alias_no_section_holds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        HomeIssueSection::Stories->unit('bannerImage');
    }

    public function test_every_alias_a_section_holds_is_in_the_morph_map(): void
    {
        // Walked from the enum, not the provider: an alias spelled only here would write ledger rows
        // the morph map cannot resolve back.
        foreach (HomeIssueSection::cases() as $section) {
            foreach ($section->sourceTypes() as $alias) {
                $this->assertNotNull(
                    Relation::getMorphedModel($alias),
                    "[{$alias}] is held by section [{$section->value}] but is not a morph alias.",
                );
            }
        }
    }

    public function test_the_pairs_above_are_every_pair_there_is(): void
    {
        // Keeps the provider honest: a new (section, alias) pair fails here until its expected unit
        // is written down, rather than shipping ungated.
        $declared = [];
        foreach (HomeIssueSection::cases() as $section) {
            foreach ($section->sourceTypes() as $alias) {
                $declared[] = "{$section->value}:{$alias}";
            }
        }

        $covered = array_map(
            fn (array $case): string => $case[0]->value.':'.$case[1],
            array_values(self::units()),
        );

        sort($declared);
        sort($covered);
        $this->assertSame($declared, $covered);
    }
}
