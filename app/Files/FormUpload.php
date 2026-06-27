<?php

namespace App\Files;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

/**
 * Extracts the uploaded file from a Filament FileUpload's form state.
 *
 * Filament 5.6's FileUploadStateCast returns a single (non-multiple) upload as the UploadedFile
 * directly, whereas a multiple field (and the pre-5.6 shape) yields an array keyed by token. The old
 * `Arr::first((array) $state)` idiom casts the single UploadedFile *object* to an array of its
 * properties and so returns a string — which is why storeFiles(false) uploads stopped reaching
 * FileUploader. Handle both shapes here, in one tested place, so it cannot drift across call sites.
 */
class FormUpload
{
    public static function single(mixed $state): ?UploadedFile
    {
        $value = is_array($state) ? Arr::first($state) : $state;

        return $value instanceof UploadedFile ? $value : null;
    }
}
