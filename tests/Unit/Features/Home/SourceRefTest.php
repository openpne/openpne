<?php

declare(strict_types=1);

namespace Tests\Unit\Features\Home;

use App\Features\Home\Data\SourceRef;
use App\Models\Diary;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The `alias:id` spelling, which is the form a source reference takes anywhere outside the ledger's
 * two columns.
 */
class SourceRefTest extends TestCase
{
    public function test_a_featurable_alias_parses(): void
    {
        $ref = SourceRef::tryParse('timelinePost:12');

        $this->assertNotNull($ref);
        $this->assertSame('timelinePost', $ref->type);
        $this->assertSame(12, $ref->id);
    }

    public function test_an_alias_no_section_features_does_not_parse(): void
    {
        // A real morph alias, and still not a source ref: nothing puts a banner image in an issue.
        $this->assertNull(SourceRef::tryParse('bannerImage:1'));
        $this->assertNull(SourceRef::tryParse('diaryComment:1'));
    }

    /** @return array<string, array{0: string}> */
    public static function malformed(): array
    {
        return [
            'no id' => ['diary'],
            'empty id' => ['diary:'],
            'zero' => ['diary:0'],
            'negative' => ['diary:-1'],
            'leading zero' => ['diary:01'],
            'not a number' => ['diary:abc'],
            'no alias' => [':1'],
            'empty' => [''],
            'separator only' => [':'],
            'digits in the alias' => ['diary2:1'],
            'trailing text' => ['diary:1x'],
            'two separators' => ['diary:1:2'],
            'padded' => [' diary:1'],
            'newline past the anchor' => ["diary:1\nbannerImage:1"],
            // `$` would let a trailing newline through; only an absolute end anchor refuses it.
            'trailing newline' => ["diary:1\n"],
            // Would cast to PHP_INT_MAX and quietly name a row nobody wrote.
            'past the integer range' => ['diary:99999999999999999999'],
        ];
    }

    #[DataProvider('malformed')]
    public function test_garbage_does_not_parse(string $text): void
    {
        $this->assertNull(SourceRef::tryParse($text));
    }

    public function test_of_uses_the_alias_the_ledger_would_store(): void
    {
        $this->assertSame('diary:3', SourceRef::of($this->keyed(new Diary, 3))->key());
        $this->assertSame('member:5', SourceRef::of($this->keyed(new Member, 5))->key());
    }

    public function test_of_resolves_a_group_to_its_current_alias_not_a_legacy_one(): void
    {
        // The morph map keeps `community` readable behind `group`; getMorphClass() returns the first
        // key, and that is the one a section allows.
        $this->assertSame('group', SourceRef::of($this->keyed(new Group, 7))->type);
    }

    public function test_the_key_round_trips(): void
    {
        $ref = SourceRef::tryParse('groupEvent:99');

        $this->assertNotNull($ref);
        $this->assertSame('groupEvent:99', $ref->key());
        $this->assertEquals($ref, SourceRef::tryParse($ref->key()));
    }

    public function test_of_round_trips_through_the_key(): void
    {
        $ref = SourceRef::of($this->keyed(new Diary, 42));

        $this->assertEquals($ref, SourceRef::tryParse($ref->key()));
    }

    /** An unsaved model standing in for a stored one: getMorphClass() and getKey() need no database. */
    private function keyed(Model $model, int $id): Model
    {
        $model->setAttribute($model->getKeyName(), $id);

        return $model;
    }
}
