<?php

namespace Tests\Feature\Database;

use App\Features\Diary\Queries\SearchDiaries;
use App\Features\DirectMessage\Queries\RecipientCandidates;
use App\Features\Group\Queries\SearchGroups;
use App\Features\GroupTalk\Queries\GroupTalkMentionCandidates;
use App\Features\Member\Queries\SearchMembers;
use App\Features\Timeline\Queries\MentionCandidates;
use App\Filament\Resources\Profiles\Pages\ListProfiles;
use App\Models\AdminUser;
use App\Models\Diary;
use App\Models\Group;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use Filament\Facades\Filament;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** A plain LIKE on a binary column folds nothing, so a match across case or kana is what shows a search went through SearchCollation. */
class SearchCollationSitesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function sites(): array
    {
        return [
            'member search by name' => ['memberName'],
            'member search by a profile value' => ['profileValue'],
            'group search' => ['groupName'],
            'diary search by title' => ['diaryTitle'],
            'diary search by body' => ['diaryBody'],
            'timeline mention candidates' => ['mentionCandidate'],
            'talk mention candidates' => ['talkMentionCandidate'],
            'message recipient candidates' => ['recipientCandidate'],
            'admin profile field search by caption' => ['profileCaption'],
        ];
    }

    #[DataProvider('sites')]
    public function test_ascii_case_is_folded_on_both_engines(string $site): void
    {
        $this->assertTrue($this->{$site}('tanaka', 'TANAKA'));
    }

    /** A backslash is MySQL's and MariaDB's LIKE escape unless the statement names another. */
    #[DataProvider('sites')]
    public function test_a_typed_wildcard_matches_only_itself(string $site): void
    {
        $this->assertFalse($this->{$site}('abc', '%'));
        $this->assertFalse($this->{$site}('abd', '_'));
        $this->assertFalse($this->{$site}('x1y', 'x_y'));
        $this->assertTrue($this->{$site}('x_y', 'x_y'));
        $this->assertFalse($this->{$site}('p%q', '\\%'));
        $this->assertTrue($this->{$site}('p\\%q', '\\%'));
        $this->assertTrue($this->{$site}('r!s', '!'));
    }

    #[DataProvider('sites')]
    public function test_mysql_folds_kana_but_tells_the_voiced_mark_apart(string $site): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('mysql only');
        }

        $this->assertTrue($this->{$site}('タナカ', 'たなか'));
        $this->assertFalse($this->{$site}('ば', 'は'));
    }

    #[DataProvider('sites')]
    public function test_mariadb_tells_kana_and_the_voiced_mark_apart(string $site): void
    {
        if (DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('mariadb only');
        }

        $this->assertFalse($this->{$site}('タナカ', 'たなか'));
        $this->assertFalse($this->{$site}('ば', 'は'));
    }

    private function memberName(string $stored, string $term): bool
    {
        $member = Member::factory()->create(['name' => $stored]);

        return $this->memberSearchFinds($member, $term, []);
    }

    private function profileValue(string $stored, string $term): bool
    {
        $profile = Profile::factory()->create(['form_type' => 'input']);
        $member = Member::factory()->create();
        MemberProfile::factory()->create(['member_id' => $member->getKey(), 'profile_id' => $profile->getKey(), 'value' => $stored]);

        return $this->memberSearchFinds($member, '', [$profile->getKey() => $term]);
    }

    /** @param  array<int, string>  $profile */
    private function memberSearchFinds(Member $member, string $name, array $profile): bool
    {
        $found = app(SearchMembers::class)(Member::factory()->create(), $name, $profile, []);

        return collect($found->items())->contains(fn (Member $row): bool => $row->is($member));
    }

    private function groupName(string $stored, string $term): bool
    {
        $group = Group::factory()->create(['name' => $stored]);

        return collect(app(SearchGroups::class)($term)->items())->contains(fn (Group $row): bool => $row->is($group));
    }

    private function diaryTitle(string $stored, string $term): bool
    {
        return $this->diarySearchFinds(Diary::factory()->create(['title' => $stored, 'body' => 'x']), $term);
    }

    private function diaryBody(string $stored, string $term): bool
    {
        return $this->diarySearchFinds(Diary::factory()->create(['title' => 'x', 'body' => $stored]), $term);
    }

    private function diarySearchFinds(Diary $diary, string $term): bool
    {
        $query = Diary::query()->whereKey($diary->getKey());
        SearchDiaries::applyTerms($query, $term);

        return $query->exists();
    }

    private function mentionCandidate(string $stored, string $term): bool
    {
        $member = Member::factory()->create(['name' => $stored]);

        return app(MentionCandidates::class)(Member::factory()->create(), $term)->contains($member);
    }

    private function talkMentionCandidate(string $stored, string $term): bool
    {
        $group = Group::factory()->create();
        $viewer = Member::factory()->create();
        $member = Member::factory()->create(['name' => $stored]);
        foreach ([$viewer, $member] as $joined) {
            DB::table('group_members')->insert([
                'group_id' => $group->getKey(), 'member_id' => $joined->getKey(), 'role' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return app(GroupTalkMentionCandidates::class)($viewer, $group, $term)->contains($member);
    }

    private function recipientCandidate(string $stored, string $term): bool
    {
        $member = Member::factory()->create(['name' => $stored]);

        return app(RecipientCandidates::class)(Member::factory()->create(), $term)->contains($member);
    }

    private function profileCaption(string $stored, string $term): bool
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
        // The name column is searched too, and the factory's names carry an underscore.
        $profile = Profile::factory()->create(['form_type' => 'input', 'name' => 'f'.Str::lower(Str::random(12))]);
        $profile->setTranslation('ja_JP', $stored);
        $profile->setTranslation('en', $stored);

        $records = Livewire::test(ListProfiles::class)->searchTable($term)->instance()->getTableRecords();

        return collect($records instanceof Paginator ? $records->items() : $records)->contains(fn (Profile $row): bool => $row->is($profile));
    }
}
