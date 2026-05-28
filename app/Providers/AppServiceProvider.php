<?php

namespace App\Providers;

use App\Services\TermService;
use App\Translation\TermTranslator;
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
        //
    }
}
