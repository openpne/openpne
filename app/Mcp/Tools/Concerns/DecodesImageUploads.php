<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Files\PostImages;
use App\Files\UploadLimit;
use App\Http\Requests\Concerns\PostImageRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\JsonSchema\Types\ArrayType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use RuntimeException;

/**
 * Every bound is applied to the encoded text before a decode; what a picture is is then decided by
 * reading the bytes, never by anything the caller said about them.
 */
trait DecodesImageUploads
{
    /**
     * The temporary files are removed on every path out, a refused picture and a rolled-back write
     * included.
     *
     * @template T
     *
     * @param  callable(array<int, UploadedFile>): T  $write
     * @return T
     */
    protected function withImageUploads(Request $request, callable $write): mixed
    {
        $encoded = self::encodedImages($request);
        $uploads = [];

        try {
            foreach ($encoded as $index => $image) {
                $uploads[] = self::upload($image, $index);
            }

            // The compose forms' own rules, run against the decoded bytes.
            Validator::make(['images' => $uploads], PostImageRules::rules())->validate();

            return $write($uploads);
        } finally {
            foreach ($uploads as $upload) {
                $path = $upload->getPathname();

                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    protected function imagesSchema(JsonSchema $schema): ArrayType
    {
        return $schema->array()->items($schema->string())->max(PostImages::MAX_IMAGES)
            ->description(
                'Pictures to attach, at most '.PostImages::MAX_IMAGES.'. Each is the file\'s own bytes as standard '
                .'base64 — no data: prefix, no url-safe substitutions — and at most '
                .UploadLimit::kilobytes().' KB once decoded. jpeg, png, gif or webp: what a '
                .'picture is is decided by reading it, whatever it is called.',
            );
    }

    /**
     * @return list<string>
     */
    private static function encodedImages(Request $request): array
    {
        $images = $request->get('images');

        if ($images === null) {
            return [];
        }

        Validator::make(['images' => $images], [
            'images' => ['array', 'list', 'max:'.PostImages::MAX_IMAGES],
            'images.*' => ['string'],
        ])->validate();

        $limit = self::maxEncodedLength();
        $total = 0;

        foreach ($images as $index => $image) {
            $total += strlen($image);

            // base64_decode() allocates its output from the length of its input, so a string judged
            // only after the decode is a string already in memory.
            if (strlen($image) > $limit || $total > $limit * PostImages::MAX_IMAGES) {
                throw ValidationException::withMessages(['images.'.$index => [self::tooLongMessage($limit)]]);
            }
        }

        return array_values($images);
    }

    /**
     * Four base64 characters carry at most three bytes, so a longer string cannot decode to
     * something the compose rules' cap would accept. Exact: slack here is bytes over the cap
     * being decoded.
     */
    private static function maxEncodedLength(): int
    {
        return intdiv(UploadLimit::bytes() + 2, 3) * 4;
    }

    private static function upload(string $encoded, int $index): UploadedFile
    {
        // With strict: true a `data:` prefix or the url-safe alphabet is refused, while padding stays
        // optional and line breaks are skipped.
        $bytes = base64_decode($encoded, strict: true);

        if ($bytes === false) {
            throw ValidationException::withMessages([
                'images.'.$index => ['This picture is not standard base64. Send the file\'s bytes base64-encoded, with no data: prefix and no url-safe substitutions.'],
            ]);
        }

        $path = tempnam(sys_get_temp_dir(), 'openpne-mcp-image');

        if ($path === false) {
            throw new RuntimeException('Unable to open a temporary file for an uploaded picture.');
        }

        if (file_put_contents($path, $bytes) === false) {
            unlink($path);

            throw new RuntimeException('Unable to write an uploaded picture to its temporary file.');
        }

        // `test: true` is how a non-HTTP path gets past is_uploaded_file(); the name is the server's
        // own, so nothing a caller wrote reaches the filesystem or the stored `original_filename`.
        return new UploadedFile($path, 'upload', null, null, true);
    }

    private static function tooLongMessage(int $limit): string
    {
        return 'This picture is longer than a picture may be: at most '
            .UploadLimit::kilobytes().' KB, which is '.$limit.' base64 characters.';
    }
}
