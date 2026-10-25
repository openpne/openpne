<?php

namespace App\Upgrade\Steps;

use App\Upgrade\ActiveMember;
use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `file` → OpenPNE 4 `files`, id and `name` (the storage/URL token) verbatim so file_bin
 * and every owning row resolve by id. OpenPNE 3 has no owner column, so the owner is resolved by
 * correlated subquery over ownedFileReferences(), then from the name for namePrefixOwners(); a file
 * no owner claims keeps a null owner, which FilePolicy resolves as private.
 */
class FileUpgrade extends UpgradeStep
{
    protected string $source = 'file';

    protected string $target = 'files';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'name' => Column::source('name'),
            'type' => Column::expr($this->typeExpr(), uses: ['type']),
            'original_filename' => Column::source('original_filename'),
            'byte_size' => Column::source('filesize'),
            'related_entity_type' => Column::expr($this->ownerTypeExpr(), uses: ['id', 'name']),
            'related_entity_id' => Column::expr($this->ownerIdExpr(), uses: ['id', 'name']),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function targetDefaults(): array
    {
        // A null explicit_visibility inherits from the owner; OpenPNE 3 records neither image dimensions
        // nor animation, so width/height/animated arrive null (docs/internals/images.md, "files.width / files.height").
        return ['explicit_visibility', 'width', 'height', 'animated'];
    }

    /**
     * The OpenPNE 3 `table.file_id` references this step assigns an owner to, keyed by "table.column"
     * (suffixed "#type" where one column is owned by several types with disjoint `extra`s) for the
     * coverage audit. Each value is the morph alias plus the columns the owner id is read from:
     * `id` the owner-id source column, optional `extra` an extra correlation appended to the WHERE.
     *
     * @return array<string, array{type: string, table: string, file: string, id: string, extra?: string}>
     */
    public function ownedFileReferences(): array
    {
        return [
            // Only an activated member owns the avatar: MemberImageUpgrade drops the join row for a
            // skipped one, so claiming it here would point related_entity_id at no member.
            'member_image.file_id' => ['type' => 'member', 'table' => 'member_image', 'file' => 'file_id', 'id' => 'member_id',
                'extra' => ' AND '.ActiveMember::referenceGuard('member_image', 'member_id')],
            // The group top image is a direct column on `community` (not a join table): the owner
            // is the group itself, so the id source is the group's own id.
            'community.file_id' => ['type' => 'group', 'table' => 'community', 'file' => 'file_id', 'id' => 'id'],
            'diary_image.file_id' => ['type' => 'diary', 'table' => 'diary_image', 'file' => 'file_id', 'id' => 'diary_id'],
            'diary_comment_image.file_id' => ['type' => 'diaryComment', 'table' => 'diary_comment_image', 'file' => 'file_id', 'id' => 'diary_comment_id'],
            'community_topic_image.file_id' => ['type' => 'groupTopic', 'table' => 'community_topic_image', 'file' => 'file_id', 'id' => 'post_id'],
            'community_topic_comment_image.file_id' => ['type' => 'groupTopicComment', 'table' => 'community_topic_comment_image', 'file' => 'file_id', 'id' => 'post_id'],
            'community_event_image.file_id' => ['type' => 'groupEvent', 'table' => 'community_event_image', 'file' => 'file_id', 'id' => 'post_id'],
            'community_event_comment_image.file_id' => ['type' => 'groupEventComment', 'table' => 'community_event_comment_image', 'file' => 'file_id', 'id' => 'post_id'],
            // Only a personal message owns its attachment; non-personal message types are not migrated.
            'message_file.file_id' => ['type' => 'directMessage', 'table' => 'message_file', 'file' => 'file_id', 'id' => 'message_id', 'extra' => $this->personalMessageExtra()],
            // The banner image row itself is the owner (groups/messages own by the parent id;
            // banners own through the banner_image pool, mirroring how the app stores them).
            'banner_image.file_id' => ['type' => 'bannerImage', 'table' => 'banner_image', 'file' => 'file_id', 'id' => 'id'],
            // An activity image is owned by the post or talk message its thread lands as (ActivityThread);
            // an image whose activity is not migrated matches neither arm and keeps the null owner.
            'activity_image.file_id#timelinePost' => ['type' => 'timelinePost', 'table' => 'activity_image', 'file' => 'file_id', 'id' => 'activity_data_id',
                'extra' => ' AND '.ActivityThread::imageOf(ActivityThread::landsOnTimeline('activity_data'))],
            'activity_image.file_id#groupMessage' => ['type' => 'groupMessage', 'table' => 'activity_image', 'file' => 'file_id', 'id' => 'activity_data_id',
                'extra' => ' AND '.ActivityThread::imageOf(ActivityThread::landsInGroup('activity_data'))],
        ];
    }

    /**
     * opUploadFilePlugin recorded a file's owner only in its name, `/m{member_id}/…` or `/c{community_id}/…`
     * (OpenPNE3/plugins/opUploadFilePlugin, api f/upload), so these arms read the id out of the name and come
     * after every referencing-table arm (docs/internals/upgrade.md, "OpenPNE 3 upload files").
     *
     * @return array<string, array{prefix: string, table: string, guard: ?string}> morph alias => name prefix letter, owner table, extra SQL boolean on `named_owner`
     */
    public function namePrefixOwners(): array
    {
        return [
            'member' => ['prefix' => 'm', 'table' => 'member', 'guard' => ActiveMember::predicate('named_owner')],
            'group' => ['prefix' => 'c', 'table' => 'community', 'guard' => null],
        ];
    }

    /** SQL integer: the owner id between the prefix letter and the next slash of `file`.`name`. */
    public static function nameOwnerIdExpr(): string
    {
        return "CAST(SUBSTRING_INDEX(SUBSTRING(`file`.`name`, 3), '/', 1) AS UNSIGNED)";
    }

    /**
     * SQL boolean: `file`.`name` carries this owner's prefix with an id the owner table holds. The digit
     * run is capped so the CAST cannot overflow, and the match is case-sensitive like the plugin's writes.
     *
     * @param  array{prefix: string, table: string, guard: ?string}  $spec
     */
    public static function nameOwnerExists(array $spec): string
    {
        return sprintf(
            "(REGEXP_LIKE(`file`.`name`, '^/%s[1-9][0-9]{0,9}/', 'c') AND EXISTS (SELECT 1 FROM %s AS `named_owner` WHERE `named_owner`.`id` = %s%s))",
            $spec['prefix'],
            SourceRef::table($spec['table']),
            self::nameOwnerIdExpr(),
            $spec['guard'] === null ? '' : ' AND '.$spec['guard'],
        );
    }

    /**
     * One reference's owner rows as a SELECT of file_id / owner_type / owner_id, for a query over every
     * owner at once (the shared-file preflight).
     *
     * @param  array{type: string, table: string, file: string, id: string, extra?: string}  $reference
     */
    public static function ownerRowsSelect(array $reference): string
    {
        return sprintf(
            "SELECT `%2\$s`.`%3\$s` AS `file_id`, '%4\$s' AS `owner_type`, `%2\$s`.`%5\$s` AS `owner_id` FROM %1\$s AS `%2\$s` WHERE `%2\$s`.`%3\$s` IS NOT NULL%6\$s",
            SourceRef::table($reference['table']), $reference['table'], $reference['file'], $reference['type'], $reference['id'], $reference['extra'] ?? '',
        );
    }

    /**
     * OpenPNE 3's validator admitted `image/pjpeg` and `image/x-png` (lower-cased), and its API upload
     * wrote the browser's type unvalidated; the aliases become the types this version shows, every
     * other type is only lower-cased.
     */
    private function typeExpr(): string
    {
        return "CASE LOWER(`file`.`type`) WHEN 'image/pjpeg' THEN 'image/jpeg' WHEN 'image/x-png' THEN 'image/png' ELSE LOWER(`file`.`type`) END";
    }

    /** CASE returning the morph alias of the owning entity, or NULL when none owns the file. */
    private function ownerTypeExpr(): string
    {
        $arms = '';
        foreach ($this->ownedFileReferences() as $reference) {
            $arms .= sprintf('WHEN %s THEN %s ', self::ownerExists($reference), "'{$reference['type']}'");
        }
        foreach ($this->namePrefixOwners() as $type => $spec) {
            $arms .= sprintf('WHEN %s THEN %s ', self::nameOwnerExists($spec), "'{$type}'");
        }

        return "CASE {$arms}ELSE NULL END";
    }

    /** CASE returning the owning entity's id (member, post, message, banner image …), or NULL. */
    private function ownerIdExpr(): string
    {
        $arms = '';
        foreach ($this->ownedFileReferences() as $reference) {
            $arms .= sprintf('WHEN %s THEN %s ', self::ownerExists($reference), $this->ownerId($reference));
        }
        foreach ($this->namePrefixOwners() as $spec) {
            $arms .= sprintf('WHEN %s THEN %s ', self::nameOwnerExists($spec), self::nameOwnerIdExpr());
        }

        return "CASE {$arms}ELSE NULL END";
    }

    /**
     * SQL boolean: a row of the reference's table points at `file`.`id`.
     *
     * @param  array{table: string, file: string, extra?: string}  $reference
     */
    public static function ownerExists(array $reference): string
    {
        // The owner table goes through SourceRef and is aliased to its original name, so the column
        // qualifiers (and a message extra referencing it) resolve under a source prefix / database.
        return sprintf(
            'EXISTS (SELECT 1 FROM %1$s AS `%2$s` WHERE `%2$s`.`%3$s` = `file`.`id`%4$s)',
            SourceRef::table($reference['table']), $reference['table'], $reference['file'], $reference['extra'] ?? '',
        );
    }

    /** @param array{table: string, file: string, id: string, extra?: string} $reference */
    private function ownerId(array $reference): string
    {
        return sprintf(
            '(SELECT `%2$s`.`%3$s` FROM %1$s AS `%2$s` WHERE `%2$s`.`%4$s` = `file`.`id`%5$s ORDER BY `%2$s`.`id` LIMIT 1)',
            SourceRef::table($reference['table']), $reference['table'], $reference['id'], $reference['file'], $reference['extra'] ?? '',
        );
    }

    /** Restricts a message attachment to one whose parent is a personal message (the migrated type). */
    private function personalMessageExtra(): string
    {
        return ' AND EXISTS (SELECT 1 FROM '.SourceRef::table('message').' `p` WHERE `p`.`id` = `message_file`.`message_id` '
            .'AND `p`.`message_type_id` IN (SELECT `id` FROM '.SourceRef::table('message_type')." WHERE `type_name` = 'message'))";
    }
}
