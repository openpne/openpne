<?php

namespace App\Files;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

/** The file-chip shape every Modern serializer ships for a non-picture attachment, built here so it has one producer. */
final class AttachmentShape
{
    /** @return array{id: int, url: string, name: string, byteSize: int, sizeLabel: string, type: string} */
    public static function of(Model $slot): array
    {
        $file = $slot->file;

        return [
            'id' => (int) $slot->getKey(),
            'url' => $file?->url() ?? '',
            'name' => (string) ($file?->original_filename ?? ''),
            'byteSize' => (int) ($file?->byte_size ?? 0),
            'sizeLabel' => Number::fileSize((int) ($file?->byte_size ?? 0), precision: 1),
            'type' => (string) ($file?->type ?? ''),
        ];
    }
}
