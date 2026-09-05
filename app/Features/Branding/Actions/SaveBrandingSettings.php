<?php

declare(strict_types=1);

namespace App\Features\Branding\Actions;

use App\Files\FileUploader;
use App\Models\File;
use App\Services\SnsSettingService;
use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Every File stored during this save is deleted again if anything later throws, the fail-closed
 * ImageMetadataStripException included — that one still propagates for the caller to report. The
 * superseded token is re-read under a row lock inside the transaction rather than taken from the form
 * the admin rendered, so a concurrent save cannot leave its file orphaned.
 */
class SaveBrandingSettings
{
    public function __construct(
        private readonly FileUploader $uploader,
        private readonly SnsSettingService $settings,
    ) {}

    /**
     * @param  array<string, UploadedFile|null>  $files  keyed by the file setting's stored key: an
     *                                                   UploadedFile replaces it, null clears it, an
     *                                                   absent key keeps the stored token.
     */
    public function __invoke(string $brandColor, array $files = []): void
    {
        // Reject unknown keys before storing anything: persist() would silently drop them and the
        // uploaded file would outlive the save as an orphan.
        $known = [SnsSettingKey::BrandLogoFile->value, SnsSettingKey::BrandFaviconFile->value];
        if ($unknown = array_diff(array_keys($files), $known)) {
            throw new InvalidArgumentException('Not a branding file setting: '.implode(', ', $unknown));
        }

        /** @var list<File> $stored */
        $stored = [];

        try {
            $tokens = [];
            foreach ($files as $key => $upload) {
                if ($upload === null) {
                    $tokens[$key] = '';

                    continue;
                }

                $file = $this->uploader->store($upload, explicitVisibility: File::VISIBILITY_PUBLIC);
                $stored[] = $file;
                $tokens[$key] = $file->name;
            }

            $superseded = DB::transaction(fn (): array => $this->persist($brandColor, $tokens));
        } catch (Throwable $e) {
            foreach ($stored as $file) {
                $file->delete();
            }

            throw $e;
        }

        // Before the deletes: a request that reads the old token from a stale cache would otherwise
        // be handed a file that is already gone.
        $this->settings->clearCache();

        // After the commit: a disk backend's byte deletion cannot be rolled back.
        foreach ($superseded as $token) {
            $file = File::where('name', $token)->first();

            if ($file !== null && $this->isOwnerlessPublicAsset($file)) {
                $file->delete();
            }
        }
    }

    /**
     * @param  array<string, string>  $tokens
     * @return list<string> the file tokens this save superseded
     */
    private function persist(string $brandColor, array $tokens): array
    {
        $superseded = [];

        foreach (SnsSettingKey::inGroup(SettingGroup::Branding) as $key) {
            if ($key === SnsSettingKey::BrandColor) {
                $value = $brandColor;
            } else {
                $current = (string) DB::table('sns_settings')
                    ->where('key', $key->value)
                    ->lockForUpdate()
                    ->value('value');

                $value = $tokens[$key->value] ?? $current;

                if ($current !== '' && $current !== $value) {
                    $superseded[] = $current;
                }
            }

            DB::table('sns_settings')->updateOrInsert(
                ['key' => $key->value],
                ['value' => $key->encode($key->coerce($value))],
            );
        }

        return $superseded;
    }

    /** Only this page's own ownerless public uploads are purged: a setting corrupted into pointing at a member's file must not take it down. */
    private function isOwnerlessPublicAsset(File $file): bool
    {
        return $file->explicit_visibility === File::VISIBILITY_PUBLIC
            && $file->related_entity_type === null
            && $file->related_entity_id === null;
    }
}
