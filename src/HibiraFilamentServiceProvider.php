<?php

namespace Hibira\Filament;

use Illuminate\Support\ServiceProvider;

class HibiraFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hibira-filament.php', 'hibira-filament');

        $this->app->singleton(LiveTables::class);
        $this->app->singleton(JobProgress::class);
        $this->app->singleton(LiveRecords::class);
        $this->app->singleton(LiveNotifications::class);
    }

    public function boot(): void
    {
        LiveRecords::handleRefreshCalls();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'hibira-filament');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'hibira-filament');

        if ($this->app->runningInConsole()) {
            $this->commands([Console\FilamentCommand::class]);

            $this->publishes([
                __DIR__.'/../config/hibira-filament.php' => $this->app->configPath('hibira-filament.php'),
            ], 'hibira-filament-config');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/hibira-filament'),
            ], 'hibira-filament-translations');
        }
    }
}
