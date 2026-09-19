<?php

namespace App\Upgrade\Steps;

use App\Features\Reactions\ReactionVocabulary;
use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared shape for the OpenPNE 3 opLikePlugin `nice` rows: one 👍 per row, on the content its
 * `foreign_table` letter names, where that content lands (docs/internals/upgrade.md, "Source preflight").
 * The alias is read off the model rather than written down, as every reaction's is.
 */
abstract class NiceReactionUpgrade extends UpgradeStep
{
    /** The record letters and their source tables; `A` (an activity) lands by the activity routing instead. */
    public const RECORD_TABLES = [
        'D' => 'diary',
        'd' => 'diary_comment',
        't' => 'community_topic_comment',
        'e' => 'community_event_comment',
    ];

    protected string $source = 'nice';

    protected string $target = 'reactions';

    /** The `foreign_table` byte opLikePlugin writes for this step's content. */
    abstract protected function letter(): string;

    /** @return class-string<Model> the content a landed like becomes */
    abstract protected function reactable(): string;

    /** SQL boolean over the alias `nice`: the liked source row exists and the transfer carries it. */
    abstract protected function landing(): string;

    public function reactableAlias(): string
    {
        return (new ($this->reactable()))->getMorphClass();
    }

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'reactable_type' => Column::expr("'".$this->reactableAlias()."'"),
            'reactable_id' => Column::source('foreign_id'),
            'member_id' => Column::source('member_id'),
            'emoji' => Column::expr("'".ReactionVocabulary::LIKE."'"),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function filter(): ?string
    {
        return self::onTable($this->letter()).' AND '.$this->landing();
    }

    public function filterColumns(): array
    {
        return ['foreign_table', 'foreign_id'];
    }

    public function targetFilter(): ?string
    {
        return "`reactable_type` = '".$this->reactableAlias()."'";
    }

    public function gaps(): array
    {
        return [
            'foreign_hash' => 'A lookup key over (foreign_table, foreign_id); the OpenPNE 4 unique key covers the same rows.',
        ];
    }

    /** SQL boolean over the alias `nice`: the liked activity satisfies `$landing` (over the alias `activity_data`). */
    public static function onActivity(string $landing): string
    {
        return 'EXISTS (SELECT 1 FROM '.SourceRef::table('activity_data').' AS `activity_data`'
            .' WHERE `activity_data`.`id` = `nice`.`foreign_id` AND '.$landing.')';
    }

    /** SQL boolean over the alias `nice`: the liked row of `$table` exists; the record steps carry every row. */
    public static function onRecord(string $table): string
    {
        return 'EXISTS (SELECT 1 FROM '.SourceRef::table($table).' AS `liked` WHERE `liked`.`id` = `nice`.`foreign_id`)';
    }

    /** Compared as bytes, as opLikePlugin's own reads do: `D` and `d` are two tables, and the source's collation is its own. */
    public static function onTable(string $letter): string
    {
        return "`nice`.`foreign_table` = CAST('{$letter}' AS BINARY)";
    }

    /**
     * The rows some reaction step carries, one branch per letter so a caller can drop the branches
     * whose tables the source lacks.
     *
     * @return array<string, string> letter => SQL boolean over the alias `nice`
     */
    public static function carriedBranches(): array
    {
        $branches = ['A' => self::onTable('A').' AND '.self::onActivity(ActivityThread::migrated('activity_data'))];
        foreach (self::RECORD_TABLES as $letter => $table) {
            $branches[$letter] = self::onTable($letter).' AND '.self::onRecord($table);
        }

        return $branches;
    }
}
