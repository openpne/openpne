<?php

namespace App\Upgrade\Steps;

use App\Upgrade\ActiveMember;
use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `file` → OpenPNE 4 `files`, id and `name` (the storage/URL token) verbatim so file_bin
 * and every owning row resolve by id. OpenPNE 3 has no owner column, so the owner is resolved by
 * correlated subquery over ownedFileReferences(); a file no owner claims keeps a null owner, which
 * FilePolicy resolves as private.
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
            'type' => Column::source('type'),
            'original_filename' => Column::source('original_filename'),
            'byte_size' => Column::source('filesize'),
            'related_entity_type' => Column::expr($this->ownerTypeExpr(), uses: ['id']),
            'related_entity_id' => Column::expr($this->ownerIdExpr(), uses: ['id']),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function targetDefaults(): array
    {
        // A null explicit_visibility inherits from the owner; OpenPNE 3 records no image dimensions,
        // so width/height arrive null for `openpne:backfill-image-dimensions` to fill.
        return ['explicit_visibility', 'width', 'height'];
    }

    /**
     * The OpenPNE 3 `table.file_id` references this step assigns an owner to, keyed by "table.column"
     * for the coverage audit. Each value is the morph alias plus the columns the owner id is read from:
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
        ];
    }

    /** CASE returning the morph alias of the owning entity, or NULL when none owns the file. */
    private function ownerTypeExpr(): string
    {
        $arms = '';
        foreach ($this->ownedFileReferences() as $reference) {
            $arms .= sprintf('WHEN %s THEN %s ', $this->ownerExists($reference), "'{$reference['type']}'");
        }

        return "CASE {$arms}ELSE NULL END";
    }

    /** CASE returning the owning entity's id (member, post, message, banner image …), or NULL. */
    private function ownerIdExpr(): string
    {
        $arms = '';
        foreach ($this->ownedFileReferences() as $reference) {
            $arms .= sprintf('WHEN %s THEN %s ', $this->ownerExists($reference), $this->ownerId($reference));
        }

        return "CASE {$arms}ELSE NULL END";
    }

    /** @param array{table: string, file: string, extra?: string} $reference */
    private function ownerExists(array $reference): string
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
