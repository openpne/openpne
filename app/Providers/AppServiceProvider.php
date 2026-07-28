<?php

namespace App\Providers;

use App\Auth\LegacyEloquentUserProvider;
use App\Captcha\AltchaCaptcha;
use App\Captcha\Captcha;
use App\Captcha\ConfigurableCaptcha;
use App\Features\Home\UnreadCounts;
use App\Features\Notifications\NotificationCenterWindow;
use App\Http\Middleware\UseAdminSessionStore;
use App\Models\BannerImage;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\File;
use App\Models\Member;
use App\Models\Message;
use App\Models\TimelinePost;
use App\Observers\MemberObserver;
use App\Policies\FilePolicy;
use App\Policies\MemberPolicy;
use App\Rules\MaxBytes;
use App\Rules\NotCommonPassword;
use App\Rules\NotContextWord;
use App\Services\SnsSettingService;
use App\Services\TermService;
use App\Translation\TermTranslator;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TermService::class);

        // Singleton so its constructor captures the member-surface session config
        // once per process, before any request's surface pin mutates it.
        $this->app->singleton(UseAdminSessionStore::class);

        // Request-scoped so the shell's nav badges and the dashboard notices reuse one set of counts.
        $this->app->scoped(UnreadCounts::class);

        // Likewise for the Classic header sprite: one window read once, whether the shell is
        // badging it or the panel is listing it (see NotificationCenterWindow).
        $this->app->scoped(NotificationCenterWindow::class);

        $this->app->singleton(Captcha::class, function ($app): Captcha {
            $config = $app['config']['openpne.captcha'];

            // Fail loudly on an unknown driver rather than silently enforcing nothing.
            $driver = match ($config['driver']) {
                'altcha' => new AltchaCaptcha(
                    $config['hmac_key'] ?: hash('sha256', (string) $app['config']['app.key'].'|altcha'),
                    (int) $config['altcha']['cost'],
                    (int) $config['altcha']['max_number'],
                    (int) $config['altcha']['expires_seconds'],
                ),
                default => throw new InvalidArgumentException("Unknown captcha driver [{$config['driver']}]."),
            };

            // Whether the challenge is enforced is the admin `captcha_enabled` setting, resolved per
            // call by the wrapper (config supplies only the driver's tuning).
            return new ConfigurableCaptcha($app->make(SnsSettingService::class), $driver);
        });

        $this->app->extend('translator', function (Translator $base, $app) {
            $wrapped = new TermTranslator(
                $app['translation.loader'],
                $base->getLocale(),
                $app->make(TermService::class),
            );
            $wrapped->setFallback($base->getFallback());

            return $wrapped;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('openpne.security.force_https')) {
            // Commit URL generation + the session cookie to HTTPS even when PHP sees a plain-HTTP
            // request (e.g. behind a TLS-terminating proxy), so links and cookies are never downgraded.
            URL::forceScheme('https');
            config(['session.secure' => true]);
        }

        // The `admins` guard uses this so an administrator carried over from OpenPNE 3 — whose
        // password the upgrade wrapped as bcrypt(md5) — can log in; the first login retires the
        // wrapper to a plain bcrypt (see LegacyEloquentUserProvider).
        Auth::provider('legacy-eloquent', fn ($app, array $config): LegacyEloquentUserProvider => new LegacyEloquentUserProvider($app['hash'], $config['model']));

        // The single password policy — every path validates via Password::default(), so this is the
        // one place the bounds live. Min 8 and the 72-BYTE cap (bcrypt reads nothing past its 72nd
        // input byte; characters would under-count multibyte) always apply; the guessability checks
        // (common-password blocklist + context words) are gated by OPENPNE_PASSWORD_BLOCKLIST so a dev
        // environment can opt out. Rationale and standards in docs/internals/security.md.
        Password::defaults(function (): Password {
            $rules = [new MaxBytes(72)];
            if (config('openpne.password.blocklist')) {
                $rules[] = new NotCommonPassword;
                $rules[] = new NotContextWord;
            }

            return Password::min(8)->rules($rules);
        });

        // Stable morph alias so a file's owner is stored as `member`, not the FQCN;
        // FilePolicy resolves the owning entity through this map.
        Relation::morphMap([
            'member' => Member::class,
            'diary' => Diary::class,
            'diaryComment' => DiaryComment::class,
            'community' => Community::class,
            'communityTopic' => CommunityTopic::class,
            'communityTopicComment' => CommunityTopicComment::class,
            'communityEvent' => CommunityEvent::class,
            'communityEventComment' => CommunityEventComment::class,
            'bannerImage' => BannerImage::class,
            'message' => Message::class,
            'timelinePost' => TimelinePost::class,
        ]);

        Gate::policy(File::class, FilePolicy::class);
        Gate::policy(Member::class, MemberPolicy::class);

        Member::observe(MemberObserver::class);

        $this->configureRateLimiting();
    }

    /**
     * Named limiters for the content-posting and mail-triggering member writes (auth-flow limiters
     * live in FortifyServiceProvider). Attached per route in routes/web.php.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('posting', $this->writeLimiter('posting', 'posting', 'posting_ip'));
        RateLimiter::for('preview', $this->writeLimiter('preview', 'preview', 'preview_ip'));
        RateLimiter::for('message-send', $this->writeLimiter('message', 'message', 'message_ip'));
        RateLimiter::for('friend-request', $this->writeLimiter('friend', 'friend', 'friend_ip'));
        RateLimiter::for('community-join', $this->writeLimiter('community', 'community', 'community_ip'));
    }

    /**
     * A two-limb write limiter: a per-member cap (primary) and a looser per-IP cap keyed under
     * distinct prefixes. Config is read per request so an env override — and the tests' config()
     * lever — take effect. A disabled (0) limb is OMITTED from the array rather than passed as
     * Limit::none(): ThrottleRequests bypasses only a none() returned as the sole response, so a
     * none() inside the array degrades to a shared-key PHP_INT_MAX limit instead of a bypass
     * (Illuminate\Routing\Middleware\ThrottleRequests::handleRequestUsingNamedLimiter). When both
     * limbs are disabled the sole none() is the correct unlimited signal.
     */
    private function writeLimiter(string $prefix, string $perMemberKey, string $perIpKey): Closure
    {
        return function (Request $request) use ($prefix, $perMemberKey, $perIpKey): array|Limit {
            $perMember = max(0, (int) config("openpne.throttle.{$perMemberKey}"));
            $perIp = max(0, (int) config("openpne.throttle.{$perIpKey}"));

            $limits = [];
            if ($perMember > 0) {
                $limits[] = Limit::perMinute($perMember)->by($prefix.'|'.($request->user()?->getKey() ?? $request->ip()));
            }
            if ($perIp > 0) {
                $limits[] = Limit::perMinute($perIp)->by($prefix.'-ip|'.$request->ip());
            }

            return $limits === [] ? Limit::none() : $limits;
        };
    }
}
