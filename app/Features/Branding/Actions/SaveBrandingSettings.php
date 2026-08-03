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
use Throwable;

/**
 * Applies one admin save of the branding settings: the brand color plus, per file setting, a new
 * upload, a removal, or no change.
 *
 * The uploads are stored before the settings transaction (FileUploader commits its own), so every
 * File stored during this save is tracked and deleted again if anything later throws — including the
 * fail-closed ImageMetadataStripException, which still propagates for the caller to turn into a
 * message. The superseded token is re-read under a row lock inside the transaction, not taken from
 * the form the admin rendered, so a concurrent save cannot leave its file orphaned. Replaced files
 * are purged only after commit (their bytes are irreversible on a disk backend), and only when they
 * are still the ownerless public assets this page uploads — a corrupted setting pointing at someone
 * else's file must not take it down with it.
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

        foreach ($superseded as $token) {
            $file = File::where('name', $token)->first();

            if ($file !== null && $this->isOwnerlessPublicAsset($file)) {
                $file->delete();
            }
        }
    }

    /**
     * Write every Branding key and report the file tokens this save superseded.
     *
     * @param  array<string, string>  $tokens
     * @return list<string>
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

    /** Whether the file is one of this page's own uploads, rather than something a member owns. */
    private function isOwnerlessPublicAsset(File $file): bool
    {
        return $file->explicit_visibility === File::VISIBILITY_PUBLIC
            && $file->related_entity_type === null
            && $file->related_entity_id === null;
    }
}
