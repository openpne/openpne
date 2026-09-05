<?php

namespace App\Features\Member;

/**
 * Keys mirror the OpenPNE 3 `member_config.yml` category keys, which the URLs carry. `General`, `Ai`
 * and `Mfa` are OpenPNE 4-native, with no OpenPNE 3 category.
 */
enum MemberConfigCategory: string
{
    case Diary = 'diary';
    case PublicFlag = 'publicFlag';
    case Language = 'language';
    case General = 'general';
    // OpenPNE 3's member/configNotification, on sites that carried the notification extension.
    case Notification = 'notification';
    case Ai = 'ai';
    case Password = 'password';
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
            self::Ai => __('AI accounts'),
            self::Password => __('Password'),
            self::Mfa => __('Two-factor authentication'),
            self::Email => __('Email address'),
            self::Withdrawal => __('Account withdrawal'),
        };
    }
}
