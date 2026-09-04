<?php

namespace App\Files;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

/**
 * Filament 5.6's `FileUploadStateCast` returns a single (non-multiple) upload as the `UploadedFile`
 * itself, where a multiple field yields an array keyed by token. Casting that object with `(array)`
 * yields its properties rather than the upload, so the shape has to be tested before it is indexed.
 */
class FormUpload
{
    public static function single(mixed $state): ?UploadedFile
    {
        $value = is_array($state) ? Arr::first($state) : $state;

        return $value instanceof UploadedFile ? $value : null;
    }
}
