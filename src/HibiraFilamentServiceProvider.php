<?php

namespace Hibira\Filament;

use Illuminate\Support\ServiceProvider;

class HibiraFilamentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'hibira-filament');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'hibira-filament');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/hibira-filament'),
            ], 'hibira-filament-translations');
        }
    }
}
