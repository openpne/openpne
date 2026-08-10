<?php

declare(strict_types=1);

namespace App\Notifications\Settings;

/**
 * One NotificationKind's registry entry (see NotificationKind::definition()). Untranslated:
 * caption is a source string; __() is applied where it is displayed.
 */
final readonly class NotificationKindDefinition
{
    public function __construct(
        public NotificationCategory $category,
        /** Member-facing toggle label (untranslated source string; %term% placeholders apply). */
        public string $caption,
        /**
         * The item name in the OpenPNE 3 extension's notification_config.yml, or null for an
         * OpenPNE 4 native kind — one the extension never stored, so the upgrade derives no
         * member_config key from it. The member_config keys derive from it exactly as the
         * extension stored them: web = "is_send_{name}_web", mail = "is_send_pc_{name}_mail".
         */
        public ?string $op3Name = null,
        /**
         * The "(x only)" variant relation: this kind only takes effect while $dependOnNot is
         * disabled for the recipient — an enabled $dependOnNot already covers the narrower
         * audience.
         */
        public ?NotificationKind $dependOnNot = null,
        /**
         * Whether OpenPNE 4 has a sender for this kind. Unwired kinds exist for import fidelity
         * (the one-shot upgrade preserves a choice the extension stored) but are hidden from the
         * settings UI.
         */
        public bool $isWired = false,
    ) {}
}
