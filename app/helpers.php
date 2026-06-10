<?php

declare(strict_types=1);

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;

if (! function_exists('sns_name')) {
    /** Site SNS name (header/logo, page titles, mail), or the configured app name by default. */
    function sns_name(): string
    {
        return (string) app(SnsSettingService::class)->get(SnsSettingKey::SnsName);
    }
}

if (! function_exists('sns_title')) {
    /** Document title for the modern surface; empty by default (callers fall back to sns_name()). */
    function sns_title(): string
    {
        return (string) app(SnsSettingService::class)->get(SnsSettingKey::SnsTitle);
    }
}

if (! function_exists('sns_admin_mail_address')) {
    /** From-address for system mail, or the configured mail.from.address by default. */
    function sns_admin_mail_address(): string
    {
        return (string) app(SnsSettingService::class)->get(SnsSettingKey::AdminMailAddress);
    }
}
