<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use InvalidArgumentException;

/**
 * A warning the run reports: the code is declared on the origin that emits it and registered here,
 * the only place its category is decided (docs/internals/upgrade.md, "Run report"). Count is the
 * number the message prints, in that code's own unit, never summed across codes.
 */
final class Warning
{
    public const CODE_PATTERN = '/^W-[A-Z0-9]+(?:-[A-Z0-9]+)*$/';

    public const DROPPED = 'dropped';

    public const REWRITTEN = 'rewritten';

    public const REVIEW = 'review';

    public const ENV = 'env';

    public const UNKNOWN = 'unknown';

    /** @var array<string, string> code => category */
    public const CATEGORY_OF = [
        SourcePreflight::W_ABSENT_PLUGIN => self::DROPPED,
        SourcePreflight::W_UNKNOWN_NAME => self::UNKNOWN,
        UncopiedSettingsNotice::W_SETTING_IN_ENV => self::ENV,
        UncopiedSettingsNotice::W_SETTING_UNREADABLE => self::ENV,
        UncopiedSettingsNotice::W_SETTING_NULL => self::REVIEW,
        MailTemplatePreflight::W_INERT_LOCALE => self::REVIEW,
        MailTemplatePreflight::W_BROKEN => self::REVIEW,
        MailTemplatePreflight::W_DEGRADED => self::REVIEW,
        ActivityPreflight::W_DEEP_REPLY => self::REWRITTEN,
        ActivityPreflight::W_DANGLING_REPLY => self::REWRITTEN,
        ActivityPreflight::W_CROSS_SCOPE_REPLY => self::REWRITTEN,
        ActivityPreflight::W_UNRESOLVABLE_REPLY => self::DROPPED,
        ActivityPreflight::W_OTHER_SCOPE => self::DROPPED,
        ActivityPreflight::W_ORPHAN_GROUP_THREAD => self::DROPPED,
        ActivityPreflight::W_NON_MEMBERS_GROUP_THREAD => self::DROPPED,
        ActivityPreflight::W_URI_ONLY_IMAGE => self::DROPPED,
        ActivityPreflight::W_TEMPLATE_RENDERED => self::REWRITTEN,
        ActivityPreflight::W_TEMPLATE_DROPPED => self::DROPPED,
        ActivityPreflight::W_TEMPLATE_UNKNOWN => self::REVIEW,
        NicePreflight::W_UNMIGRATED_ACTIVITY => self::DROPPED,
        NicePreflight::W_GONE_TARGET => self::DROPPED,
        NicePreflight::W_UNINSTALLED_TARGET => self::DROPPED,
        NicePreflight::W_UNKNOWN_TABLE => self::UNKNOWN,
        UploadFileOwnerPreflight::W_OWNERLESS => self::REVIEW,
        ActivityTemplateTransform::W_KEPT_UNKNOWN => self::REVIEW,
        ActivityTemplateTransform::W_KEPT_BAD_PARAMS => self::REVIEW,
        ActivityTemplateTransform::W_KEPT_NO_LINK => self::REVIEW,
        ActivityTemplateTransform::W_KEPT_UNFIT => self::REVIEW,
        ClassicThemeSelection::W_UNKNOWN_PACKAGE => self::REVIEW,
        ClassicThemeSelection::W_MISSING_PACKAGE => self::REVIEW,
    ];

    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?int $count = null,
    ) {
        if (! isset(self::CATEGORY_OF[$code])) {
            throw new InvalidArgumentException("Unregistered warning code {$code}.");
        }
    }

    public function category(): string
    {
        return self::CATEGORY_OF[$this->code];
    }

    public function line(): string
    {
        return "{$this->code}: {$this->message}";
    }
}
