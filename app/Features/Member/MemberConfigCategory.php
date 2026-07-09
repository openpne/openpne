<?php

namespace App\Features\Member;

/**
 * The Classic member-settings categories (OpenPNE 3 member/config `?category=`). Keys mirror the
 * OpenPNE 3 `member_config.yml` category keys for URL parity; the controller, nav, and view agree
 * on them. `General` holds the OpenPNE 4-native surface choice (no OpenPNE 3 source) — a deliberate
 * Classic-extension, not strict parity. With only a few settings ported the pages are thin; they
 * fill out as profile visibility, password, mail address, withdrawal, etc. land.
 */
enum MemberConfigCategory: string
{
    case Diary = 'diary';
    case PublicFlag = 'publicFlag';
    case Language = 'language';
    case General = 'general';
    // The notification catalog opt-ins (the member/configNotification page on OpenPNE 3 sites
    // that carried the notification extension — the compat redirect in routes/web.php).
    case Notification = 'notification';
    case Password = 'password';
    // OpenPNE 4-native two-factor authentication (no OpenPNE 3 source), like General.
    case Mfa = 'mfa';
    case Email = 'email';
    case Withdrawal = 'withdrawal';

    public function caption(): string
    {
        return match ($this) {
            self::Diary => __('%Diary%'),
            self::PublicFlag => __('Privacy'),
            self::Language => __('Language'),
            self::General => __('General'),
            self::Notification => __('Notifications'),
            self::Password => __('Password'),
            self::Mfa => __('Two-factor authentication'),
            self::Email => __('Email address'),
            self::Withdrawal => __('Account withdrawal'),
        };
    }
}
