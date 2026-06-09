<?php

namespace App\Providers;

use App\Captcha\AltchaCaptcha;
use App\Captcha\Captcha;
use App\Captcha\NullCaptcha;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\File;
use App\Models\Member;
use App\Observers\MemberObserver;
use App\Policies\FilePolicy;
use App\Policies\MemberPolicy;
use App\Services\TermService;
use App\Translation\TermTranslator;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\Translator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TermService::class);

        $this->app->singleton(Captcha::class, function ($app): Captcha {
            $config = $app['config']['openpne.captcha'];
            if (! $config['enabled'] || $config['driver'] !== 'altcha') {
                return new NullCaptcha;
            }

            $key = $config['hmac_key'] ?: hash('sha256', (string) $app['config']['app.key'].'|altcha');

            return new AltchaCaptcha($key, (int) $config['altcha']['cost'], (int) $config['altcha']['max_number'], (int) $config['altcha']['expires_seconds']);
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

        // Stable morph alias so a file's owner is stored as `member`, not the FQCN;
        // FilePolicy resolves the owning entity through this map.
        Relation::morphMap([
            'member' => Member::class,
            'communityTopic' => CommunityTopic::class,
            'communityTopicComment' => CommunityTopicComment::class,
            'communityEvent' => CommunityEvent::class,
            'communityEventComment' => CommunityEventComment::class,
        ]);

        Gate::policy(File::class, FilePolicy::class);
        Gate::policy(Member::class, MemberPolicy::class);

        Member::observe(MemberObserver::class);
    }
}
