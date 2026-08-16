<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Files\PostImages;
use App\Http\Requests\Concerns\PostImageRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\JsonSchema\Types\ArrayType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use RuntimeException;

/**
 * Taking pictures in over a wire that carries JSON: an `images` argument arrives as base64 text,
 * and the Actions that post them take uploads, so this decodes one into the other.
 *
 * Every bound is applied to what a caller sent before it is decoded, and the rules the compose forms
 * apply are then applied to the bytes themselves — a caller says nothing about what a picture is
 * that is believed.
 */
trait DecodesImageUploads
{
    /**
     * The most decoded bytes one picture may be. `max:5120` on the shared rules (kilobytes) stated
     * in bytes, so the bound below and the rule that re-checks it are the same number.
     */
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    /**
     * Run $write with the call's `images` decoded into uploads, and take the temporary files back
     * whatever happens — a picture the rules refuse, a write that throws, a transaction rolled back
     * around one that had already been stored.
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

            // The rules the compose forms apply, run against the bytes rather than against anything
            // the caller said about them: `image`/`mimes` decide the type by sniffing the content,
            // `dimensions` bounds the decoder's allocation, and `max` measures the decoded file.
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

    /** The `images` argument, described where it is enforced. */
    protected function imagesSchema(JsonSchema $schema): ArrayType
    {
        return $schema->array()->items($schema->string())->max(PostImages::MAX_IMAGES)
            ->description(
                'Pictures to attach, at most '.PostImages::MAX_IMAGES.'. Each is the file\'s own bytes as standard '
                .'base64 — no data: prefix, no url-safe substitutions — and at most '
                .intdiv(self::MAX_IMAGE_BYTES, 1024 * 1024).' MB once decoded. jpeg, png, gif or webp: what a '
                .'picture is is decided by reading it, whatever it is called.',
            );
    }

    /**
     * The `images` argument as base64 strings, with everything that can be settled before a decode
     * settled: its shape, how many there are, and how long each may be.
     *
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

            // Before any decode: base64_decode() allocates its output from the length of its input,
            // so a string judged only afterwards is a string already in memory. The running total is
            // the belt on the count above — what the whole array may be, whatever the count is.
            if (strlen($image) > $limit || $total > $limit * PostImages::MAX_IMAGES) {
                throw ValidationException::withMessages(['images.'.$index => [self::tooLongMessage($limit)]]);
            }
        }

        return array_values($images);
    }

    /**
     * The most base64 characters one picture may arrive as. Four characters carry at most three
     * bytes, so a longer string cannot decode to something {@see self::MAX_IMAGE_BYTES} would
     * accept. Exact, with nothing added for slack: slack here is bytes over the cap being decoded.
     */
    private static function maxEncodedLength(): int
    {
        return intdiv(self::MAX_IMAGE_BYTES + 2, 3) * 4;
    }

    /** One picture's bytes as an upload the posting Actions can take. */
    private static function upload(string $encoded, int $index): UploadedFile
    {
        // Standard base64 and nothing else: a `data:` prefix or the url-safe alphabet is refused
        // here rather than decoded into bytes nobody sent. Padding is optional and line breaks are
        // skipped, which is the decoder's own reading of the standard.
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

        // test: true is how a non-HTTP path gets past is_uploaded_file(). The name is the server's
        // own, so nothing a caller wrote reaches the filesystem or the stored row's original_filename,
        // and the client MIME type is left unsaid — the rules read the content.
        return new UploadedFile($path, 'upload', null, null, true);
    }

    private static function tooLongMessage(int $limit): string
    {
        return 'This picture is longer than a picture may be: at most '
            .intdiv(self::MAX_IMAGE_BYTES, 1024 * 1024).' MB, which is '.$limit.' base64 characters.';
    }
}
