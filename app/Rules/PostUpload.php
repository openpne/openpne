<?php

namespace App\Rules;

use App\Files\AttachmentLimit;
use App\Files\ImageIntake;
use App\Files\ImageProcessor;
use App\Http\Requests\Concerns\PostImageRules;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/**
 * One upload in a post's `images[]`: a picture the processor reads takes the picture rules, a
 * sidecar-only picture is refused where the processor cannot read it (an iPhone photo must not turn
 * into a download), anything else is a file under AttachmentLimit (docs/internals/attachments.md, "Validation").
 */
class PostUpload implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail(__('validation.file'));

            return;
        }

        $mime = (string) $value->getMimeType();
        $rules = app(ImageProcessor::class)->intake()->canonicalFormat($mime) !== null || ImageIntake::isSidecarOnly($mime)
            ? PostImageRules::imageRule()
            : ['file', 'max:'.AttachmentLimit::kilobytes()];

        $data = [];
        Arr::set($data, $attribute, $value);
        $inner = Validator::make($data, [$attribute => $rules], [], [$attribute => __('Attachments')]);

        if ($inner->fails()) {
            $fail($inner->errors()->first($attribute));
        }
    }
}
