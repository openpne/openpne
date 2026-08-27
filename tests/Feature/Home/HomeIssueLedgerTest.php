<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Features\Home\HomeIssueLedger;
use App\Features\Home\HomeIssueSection;
use App\Models\Diary;
use App\Models\Group;
use App\Models\HomeIssueItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The never-again rule as the publisher will meet it: a candidate query, minus what this section has
 * already shown.
 */
class HomeIssueLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_featured_diary_drops_out_of_the_candidates(): void
    {
        $featured = Diary::factory()->create();
        $fresh = Diary::factory()->create();

        HomeIssueItem::factory()->forSource($featured)->create(['section' => HomeIssueSection::Stories]);

        $this->assertSame([$fresh->id], $this->candidates(Diary::query(), HomeIssueSection::Stories, 'diary', 'diaries.id'));
    }

    public function test_the_ledger_is_read_per_section(): void
    {
        // A group that has been the subject of a talk item is still a candidate for being featured as
        // new: the bands answer different questions about the same row, so the talk row must not
        // reach the new-groups query.
        $group = Group::factory()->create();
        HomeIssueItem::factory()->forSource($group)->create(['section' => HomeIssueSection::Talk]);

        $this->assertSame(
            [$group->id],
            $this->candidates(Group::query(), HomeIssueSection::NewGroups, 'group', 'groups.id'),
        );

        // Same group, same query, once the section that is asking has featured it.
        HomeIssueItem::factory()->forSource($group)->create(['section' => HomeIssueSection::NewGroups]);

        $this->assertSame(
            [],
            $this->candidates(Group::query(), HomeIssueSection::NewGroups, 'group', 'groups.id'),
        );
    }

    public function test_the_ledger_is_read_per_source_type(): void
    {
        // Ids collide across tables; the alias is what tells a diary from a post.
        $diary = Diary::factory()->create();
        HomeIssueItem::factory()->create([
            'section' => HomeIssueSection::Stories,
            'source_type' => 'timelinePost',
            'source_id' => $diary->id,
        ]);

        $this->assertSame([$diary->id], $this->candidates(Diary::query(), HomeIssueSection::Stories, 'diary', 'diaries.id'));
    }

    /** @return array<string, array{0: string}> */
    public static function unqualifiedColumns(): array
    {
        return [
            // The one that matters: `id` inside the subquery is the ledger row's own id, so the
            // filter would compare source_id against it and answer something nobody asked.
            'bare id' => ['id'],
            'blank' => [''],
            'a lone dot' => ['.'],
            'no table' => ['.id'],
            'no column' => ['diaries.'],
            'two dots' => ['db.diaries.id'],
            'an expression' => ['coalesce(id, 0)'],
        ];
    }

    #[DataProvider('unqualifiedColumns')]
    public function test_an_id_column_that_does_not_name_its_table_is_refused(string $idColumn): void
    {
        $this->expectException(InvalidArgumentException::class);
        HomeIssueLedger::excludeFeatured(Diary::query(), HomeIssueSection::Stories, 'diary', $idColumn);
    }

    public function test_was_featured_answers_for_the_section_that_asked(): void
    {
        $talked = Group::factory()->create();
        $listed = Group::factory()->create();

        HomeIssueItem::factory()->forSource($talked)->create(['section' => HomeIssueSection::Talk]);
        HomeIssueItem::factory()->forSource($listed)->create(['section' => HomeIssueSection::NewGroups]);

        $this->assertTrue(HomeIssueLedger::wasFeatured(HomeIssueSection::NewGroups, 'group', $listed->id));

        // Featured, but by another section: the one asking has never shown it.
        $this->assertFalse(HomeIssueLedger::wasFeatured(HomeIssueSection::NewGroups, 'group', $talked->id));

        $this->assertFalse(HomeIssueLedger::wasFeatured(HomeIssueSection::NewGroups, 'group', $listed->id + 100));
        $this->assertFalse(HomeIssueLedger::wasFeatured(HomeIssueSection::Stories, 'diary', $listed->id));
    }

    public function test_a_source_stays_remembered_after_it_is_deleted(): void
    {
        // The rule is about what the page has shown, not about what still exists — otherwise a
        // delete-and-repost would buy a second turn at the top.
        $diary = Diary::factory()->create();
        HomeIssueItem::factory()->forSource($diary)->create(['section' => HomeIssueSection::Stories]);

        $diary->delete();

        $this->assertTrue(HomeIssueLedger::wasFeatured(HomeIssueSection::Stories, 'diary', $diary->id));
    }

    public function test_a_recurring_section_may_not_consult_the_ledger(): void
    {
        $this->expectException(LogicException::class);
        HomeIssueLedger::wasFeatured(HomeIssueSection::Talk, 'group', 1);
    }

    public function test_a_recurring_section_may_not_filter_by_the_ledger_either(): void
    {
        $this->expectException(LogicException::class);
        HomeIssueLedger::excludeFeatured(Group::query(), HomeIssueSection::UpcomingEvents, 'groupEvent', 'group_events.id');
    }

    /**
     * The candidate ids a publisher would be left with.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return list<int>
     */
    private function candidates(Builder $query, HomeIssueSection $section, string $sourceType, string $idColumn): array
    {
        HomeIssueLedger::excludeFeatured($query, $section, $sourceType, $idColumn);

        return $query->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }
}
