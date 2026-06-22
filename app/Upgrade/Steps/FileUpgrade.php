<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `file` (upload metadata) → OpenPNE 4 `files`.
 *
 * id is preserved verbatim: the bytes table (`file_bin`) keeps its rows and only re-points its
 * file_id FK from `file` onto `files`, and every owning row (member_images, *_images, message_files,
 * banner_images, communities) carries the same file_id — so the whole graph resolves by id without a
 * BLOB copy. `name` (the opaque storage/URL token) is likewise verbatim, keeping OpenPNE 3 image URLs
 * resolvable. `filesize` becomes `byte_size`.
 *
 * OpenPNE 3 `file` has no owner column — ownership lives in whichever table points at the file. Since
 * the compiler is one INSERT...SELECT per source table (no later UPDATE pass), related_entity_type /
 * related_entity_id are resolved here by reading those owning tables with correlated subqueries (the
 * member_config / message_send_list treatment). ownedFileReferences() is the single source of truth:
 * the CASE arms are built from it, and the matrix coverage audit checks it against the fixture's file
 * FKs, so an owning table cannot be wired into one without the other.
 *
 * Files an owner cannot be found for keep a null owner, which the FilePolicy resolves fail-closed
 * (private). Those are the references this step does not own: the community top image (its binary and
 * the communities.file_id link are preserved, but the community-image delivery surface is not built
 * yet, so the owner is backfilled when it lands), diary / activity / oauth-consumer images (no
 * successor surface), and attachments on non-personal messages (those messages are not migrated). All
 * `file` rows migrate regardless, so no binary is lost.
 *
 * The subqueries name the owning tables unqualified, so (like MessageUpgrade's) they are not rewritten
 * for a source prefix or separate source database — acceptable for the fleet (empty prefix, same DB).
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
        // No per-file visibility override on migration; null = inherit from the owning entity.
        return ['explicit_visibility'];
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
            'member_image.file_id' => ['type' => 'member', 'table' => 'member_image', 'file' => 'file_id', 'id' => 'member_id'],
            'community_topic_image.file_id' => ['type' => 'communityTopic', 'table' => 'community_topic_image', 'file' => 'file_id', 'id' => 'post_id'],
            'community_topic_comment_image.file_id' => ['type' => 'communityTopicComment', 'table' => 'community_topic_comment_image', 'file' => 'file_id', 'id' => 'post_id'],
            'community_event_image.file_id' => ['type' => 'communityEvent', 'table' => 'community_event_image', 'file' => 'file_id', 'id' => 'post_id'],
            'community_event_comment_image.file_id' => ['type' => 'communityEventComment', 'table' => 'community_event_comment_image', 'file' => 'file_id', 'id' => 'post_id'],
            // Only a personal message owns its attachment; non-personal message types are not migrated.
            'message_file.file_id' => ['type' => 'message', 'table' => 'message_file', 'file' => 'file_id', 'id' => 'message_id', 'extra' => $this->personalMessageExtra()],
            // The banner image row itself is the owner (communities/messages own by the parent id;
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
        return sprintf(
            'EXISTS (SELECT 1 FROM `%1$s` WHERE `%1$s`.`%2$s` = `file`.`id`%3$s)',
            $reference['table'], $reference['file'], $reference['extra'] ?? '',
        );
    }

    /** @param array{table: string, file: string, id: string, extra?: string} $reference */
    private function ownerId(array $reference): string
    {
        return sprintf(
            '(SELECT `%1$s`.`%2$s` FROM `%1$s` WHERE `%1$s`.`%3$s` = `file`.`id`%4$s ORDER BY `%1$s`.`id` LIMIT 1)',
            $reference['table'], $reference['id'], $reference['file'], $reference['extra'] ?? '',
        );
    }

    /** Restricts a message attachment to one whose parent is a personal message (the migrated type). */
    private function personalMessageExtra(): string
    {
        return ' AND EXISTS (SELECT 1 FROM `message` `p` WHERE `p`.`id` = `message_file`.`message_id` '
            ."AND `p`.`message_type_id` IN (SELECT `id` FROM `message_type` WHERE `type_name` = 'message'))";
    }
}
