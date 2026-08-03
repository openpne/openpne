<?php

declare(strict_types=1);

use App\Compat\PluginStylesheets;
use App\Compat\RouteParityRegistry;
use App\Models\Banner;
use App\Services\SnsSettingService;
use App\Support\BrandColor;
use App\Support\SnsSettingKey;
use Illuminate\Support\Facades\Schema;

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

if (! function_exists('brand_color')) {
    /**
     * The per-site brand color as `#rrggbb`, or null when none is set. A stored value that is not a
     * valid hex color reads as unset: it is inlined into a style attribute and a JSON prop, so a
     * corrupt setting must fail back to the built-in color rather than reach either.
     */
    function brand_color(): ?string
    {
        $value = (string) app(SnsSettingService::class)->get(SnsSettingKey::BrandColor);

        return BrandColor::isValid($value) ? $value : null;
    }
}

if (! function_exists('brand_logo_url')) {
    /** Public URL of the uploaded logo mark, or null when none is set (Modern falls back to an initial badge). */
    function brand_logo_url(): ?string
    {
        $token = (string) app(SnsSettingService::class)->get(SnsSettingKey::BrandLogoFile);

        return $token === '' ? null : route('file.public', ['file' => $token]);
    }
}

if (! function_exists('brand_favicon_url')) {
    /** Public URL of the uploaded favicon, or null when none is set (both surfaces keep the shipped icons). */
    function brand_favicon_url(): ?string
    {
        $token = (string) app(SnsSettingService::class)->get(SnsSettingKey::BrandFaviconFile);

        return $token === '' ? null : route('file.public', ['file' => $token]);
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

if (! function_exists('classic_plugin_css_url')) {
    /**
     * The vendored OpenPNE 3 plugin stylesheet URL for the current route — the module's view.yml
     * `stylesheets` entry — or null when its module declares none. Linked after the skin and
     * before the admin custom CSS, the order OpenPNE 3 stacked them in.
     */
    function classic_plugin_css_url(): ?string
    {
        $path = PluginStylesheets::forRoute(request()->route()?->getName());

        return $path === null ? null : asset($path);
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

if (! function_exists('classic_layout')) {
    /**
     * The Classic shell layout letter (OpenPNE 3 `id="Layout…"`) for the current route, from the
     * route-parity registry, defaulting to the global layoutC. OpenPNE 3 keyed the letter off the
     * screen's layout (setLayout / view.yml / decorate_with), not its content; gadget pages set
     * `$layout` themselves, so the shell only calls this when none was passed.
     */
    function classic_layout(): string
    {
        $name = request()->route()?->getName();

        return $name === null
            ? 'C'
            : RouteParityRegistry::layout($name) ?? 'C';
    }
}

if (! function_exists('classic_banner')) {
    /**
     * The content of a Classic banner placement (OpenPNE 3 op_banner): operator HTML (is_use_html,
     * emitted raw) or one of the placement's images at random, linked to the image's URL when set.
     * Empty when the placement is unconfigured. The caller picks the placement (top/side, by login).
     */
    function classic_banner(string $placement): string
    {
        // The shell renders before the schema exists on a pre-migrate boot (and on the error page
        // a broken database is a plausible reason to be there at all); no banners, not a crash.
        if (! Schema::hasTable('banners')) {
            return '';
        }

        $banner = Banner::where('name', $placement)->first();

        if ($banner === null) {
            return '';
        }

        if ($banner->is_use_html) {
            return (string) $banner->html;
        }

        $image = $banner->randomImage();
        $file = $image?->file;

        if ($file === null) {
            return '';
        }

        $img = sprintf('<img src="%s" alt="%s">', e(route('banner.image', $file->name)), e((string) $image->name));
        $url = (string) $image->url;

        return $url === ''
            ? $img
            : sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', e($url), $img);
    }
}

if (! function_exists('classic_top_banner')) {
    /** The Classic #topBanner content: top_after when a member is signed in, else top_before. */
    function classic_top_banner(): string
    {
        return classic_banner(auth()->check() ? 'top_after' : 'top_before');
    }
}
