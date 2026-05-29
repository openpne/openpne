<?php

namespace App\Upgrade\Diary;

/**
 * One OpenPNE 3 `diary` row, typed. Mirrors the `schema-openpne3.sql` dump:
 * title/body are TEXT (Doctrine `type: string` without length), public_flag is a
 * tinyint enum, is_open/has_images are tinyint(1) booleans, timestamps are datetimes.
 *
 * Carries the source row shape so the mapper and its tests share one definition of
 * "what OpenPNE 3 hands us". The full upgrade command will hydrate these from the
 * legacy database; until then they are built directly in tests.
 */
final readonly class LegacyDiary
{
    public function __construct(
        public int $id,
        public int $member_id,
        public string $title,
        public string $body,
        public int $public_flag,
        public bool $is_open,
        public bool $has_images,
        public string $created_at,
        public string $updated_at,
    ) {}

    /** @param array<string, mixed> $row a raw OpenPNE 3 `diary` row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            member_id: (int) $row['member_id'],
            title: (string) $row['title'],
            body: (string) $row['body'],
            public_flag: (int) $row['public_flag'],
            is_open: (bool) $row['is_open'],
            has_images: (bool) $row['has_images'],
            created_at: (string) $row['created_at'],
            updated_at: (string) $row['updated_at'],
        );
    }
}
