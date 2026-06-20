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
    /** Site title for the document <title> (both surfaces); empty by default (callers fall back to sns_name()). */
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

if (! function_exists('classic_html_slot')) {
    /**
     * Operator-supplied raw HTML for a Classic shell insertion slot (OpenPNE 3 pc_html_* settings),
     * keyed by position: head | top2 | top | bottom2 | bottom. The layout outputs it raw; it is
     * admin-trusted content (e.g. analytics tags), the same trust model as the footer.
     */
    function classic_html_slot(string $slot): string
    {
        $key = match ($slot) {
            'head' => SnsSettingKey::PcHtmlHead,
            'top2' => SnsSettingKey::PcHtmlTop2,
            'top' => SnsSettingKey::PcHtmlTop,
            'bottom2' => SnsSettingKey::PcHtmlBottom2,
            'bottom' => SnsSettingKey::PcHtmlBottom,
        };

        return (string) app(SnsSettingService::class)->get($key);
    }
}

if (! function_exists('classic_custom_css_url')) {
    /**
     * The custom-CSS stylesheet URL to <link> in the Classic head, or null when no custom CSS is set.
     * The presence check is cheap (it does not pull the CSS blob into the shared settings cache); the
     * bytes are served by App\Http\Controllers\CustomizingCssController.
     */
    function classic_custom_css_url(): ?string
    {
        return app(SnsSettingService::class)->hasCustomCss() ? route('design.customizing_css') : null;
    }
}

if (! function_exists('classic_footer_html')) {
    /**
     * Classic footer HTML for the page's security: OpenPNE 3 showed footer_after on secure (logged-in)
     * pages and footer_before on insecure (guest) pages. $secure mirrors the shell's
     * secure_page/insecure_page body class (OpenPNE 3 opToolkit::isSecurePage), not the login state.
     */
    function classic_footer_html(bool $secure): string
    {
        return (string) app(SnsSettingService::class)->get(
            $secure ? SnsSettingKey::FooterAfter : SnsSettingKey::FooterBefore,
        );
    }
}
