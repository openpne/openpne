<?php

namespace App\Providers;

use App\Models\File;
use App\Models\Member;
use App\Policies\FilePolicy;
use App\Services\TermService;
use App\Translation\TermTranslator;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
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
        // Stable morph alias so a file's owner is stored as `member`, not the FQCN;
        // FilePolicy resolves the owning entity through this map.
        Relation::morphMap([
            'member' => Member::class,
        ]);

        Gate::policy(File::class, FilePolicy::class);
    }
}
