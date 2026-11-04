<?php

namespace App\Console\Commands;

use App\Features\AiAccount\AiAccountSettings;
use App\Features\Auth\MemberCapacity;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** The supported writer of the two caps: a direct row write leaves the cached value serving for up to an hour. */
class CapacityCommand extends Command
{
    protected $signature = 'openpne:capacity {which? : member|ai} {value? : a whole number, or "unlimited"}';

    protected $description = 'Show or set the member capacity and the AI account capacity';

    public function handle(SnsSettingService $settings, MemberCapacity $members, AiAccountSettings $ai): int
    {
        $which = $this->argument('which');
        $value = $this->argument('value');

        if ($which === null) {
            $this->line('Member capacity: '.($members->limit() ?? 'unlimited').' ('.$members->members().' members, '.$members->pending().' invited)');
            $this->line('AI account capacity: '.($ai->capacity() ?? 'unlimited').' ('.$ai->inUse().' in use)');

            return self::SUCCESS;
        }

        $key = match ($which) {
            'member' => SnsSettingKey::MemberCapacity,
            'ai' => SnsSettingKey::AiAccountCapacity,
            default => null,
        };
        if ($key === null) {
            $this->error("Unknown capacity [{$which}]. Expected member or ai.");

            return self::FAILURE;
        }

        $value = trim((string) $value);
        if ($value === 'unlimited') {
            $capacity = null;
        } elseif (preg_match('/^\d+$/', $value) === 1) {
            $capacity = (int) $value;
        } else {
            $this->error("Invalid value [{$value}]. Expected a whole number or \"unlimited\".");

            return self::FAILURE;
        }

        DB::table('sns_settings')->updateOrInsert(['key' => $key->value], ['value' => $key->encode($capacity)]);
        $settings->clearCache();

        $this->info(ucfirst($which).' capacity set to '.($capacity ?? 'unlimited').'.');

        return self::SUCCESS;
    }
}
