<?php

namespace App\Console\Commands;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use App\Support\SurfaceMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Show or set the install's surface mode (App\Support\SurfaceMode) — the runtime writer for the
 * DB-authoritative sns_settings value SurfaceResolver reads. A fresh install has no row (the config
 * default applies) and the OpenPNE 3 -> 4 upgrade writes classic_default; this command is how an
 * operator switches a live site, e.g. classic_default -> modern_only once the Modern migration is done.
 */
class SurfaceModeCommand extends Command
{
    protected $signature = 'openpne:surface-mode {mode? : modern_only|classic_default|modern_default}';

    protected $description = 'Show or set the install surface mode (Classic / Modern)';

    public function handle(SnsSettingService $settings): int
    {
        $arg = $this->argument('mode');

        if ($arg === null) {
            $this->line('Surface mode: '.$settings->get(SnsSettingKey::SurfaceMode)->value);

            return self::SUCCESS;
        }

        $mode = SurfaceMode::tryFrom(trim((string) $arg));
        if ($mode === null) {
            $allowed = implode('|', array_map(static fn (SurfaceMode $m): string => $m->value, SurfaceMode::cases()));
            $this->error("Invalid surface mode [{$arg}]. Expected one of: {$allowed}.");

            return self::FAILURE;
        }

        DB::table('sns_settings')->updateOrInsert(
            ['key' => SnsSettingKey::SurfaceMode->value],
            ['value' => SnsSettingKey::SurfaceMode->encode($mode)],
        );
        $settings->clearCache();

        $this->info("Surface mode set to {$mode->value}.");

        return self::SUCCESS;
    }
}
