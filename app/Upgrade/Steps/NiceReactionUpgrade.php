<?php

namespace App\Upgrade\Steps;

use App\Features\Reactions\ReactionVocabulary;
use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared shape for the OpenPNE 3 opLikePlugin `nice` rows on activities: one 👍 per row, landing
 * where the activity's thread did (docs/internals/upgrade.md, "Activity threads"). The alias is read
 * off the model rather than written down, as every reaction's is.
 */
abstract class NiceReactionUpgrade extends UpgradeStep
{
    protected string $source = 'nice';

    protected string $target = 'reactions';

    /** @return class-string<Model> the content a landed activity became */
    abstract protected function reactable(): string;

    /** SQL boolean over the alias `activity_data`: the activity lands in this step's content. */
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
        return self::onActivity($this->landing());
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

    /** SQL boolean over the alias `nice`: a like on an activity that satisfies `$landing` (over `activity_data`). */
    public static function onActivity(string $landing): string
    {
        return self::onTable('A').' AND EXISTS (SELECT 1 FROM '.SourceRef::table('activity_data').' AS `activity_data`'
            .' WHERE `activity_data`.`id` = `nice`.`foreign_id` AND '.$landing.')';
    }

    /** Compared as bytes: opLikePlugin's own reads do, since a 0.9-era source may hold the column case-insensitive and `D` and `d` are two tables. */
    public static function onTable(string $letter): string
    {
        return "`nice`.`foreign_table` = BINARY '{$letter}'";
    }
}
