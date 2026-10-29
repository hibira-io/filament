<?php

namespace Hibira\Filament;

use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Hibira\Filament\Concerns\InteractsWithHibiraLock;
use Hibira\Filament\Widgets\HibiraChannelsWidget;
use Hibira\Laravel\Hibira;
use Livewire\Livewire;

/**
 * Points a Filament panel's Laravel Echo client at Hibira.
 */
class HibiraPlugin implements Plugin
{
    /** pusher-js requires a cluster, but does not use it once wsHost is set. */
    public const CLUSTER = 'hkg';

    protected bool $hasDatabaseNotifications = false;

    protected ?string $databaseNotificationsPolling = null;

    protected bool $hasConnectionStatus = true;

    protected string $connectionStatusHook = PanelsRenderHook::USER_MENU_BEFORE;

    protected bool $hasChannelsWidget = false;

    protected bool $hasPresence = false;

    protected string $presenceHook = PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE;

    /** @var array<string, mixed> */
    protected array $echoOptions = [];

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static */
        return filament(app(static::class)->getId());
    }

    public function getId(): string
    {
        return 'hibira';
    }

    /**
     * Turns on the panel's database notifications. They arrive over Hibira
     * as soon as they are sent, so polling is off unless you pass an
     * interval as a fallback.
     */
    public function databaseNotifications(bool $condition = true, ?string $polling = null): static
    {
        $this->hasDatabaseNotifications = $condition;
        $this->databaseNotificationsPolling = $polling;

        return $this;
    }

    /** Shows whether the browser is connected to Hibira, next to the user menu. */
    public function connectionStatus(bool $condition = true, ?string $renderHook = null): static
    {
        $this->hasConnectionStatus = $condition;

        if ($renderHook !== null) {
            $this->connectionStatusHook = $renderHook;
        }

        return $this;
    }

    /** Adds the live channels widget to the panel's dashboard. */
    public function channelsWidget(bool $condition = true): static
    {
        $this->hasChannelsWidget = $condition;

        return $this;
    }

    /** Shows the avatars of the other users on the same page. */
    public function presence(bool $condition = true, ?string $renderHook = null): static
    {
        $this->hasPresence = $condition;

        if ($renderHook !== null) {
            $this->presenceHook = $renderHook;
        }

        return $this;
    }

    /**
     * Extra or replaced Laravel Echo options, such as authEndpoint.
     *
     * @param  array<string, mixed>  $options
     */
    public function echoOptions(array $options): static
    {
        $this->echoOptions = $options;

        return $this;
    }

    public function register(Panel $panel): void
    {
        if ($this->hasDatabaseNotifications) {
            $panel->databaseNotifications();

            // Without an app key there is no connection, so keep Filament's
            // own polling until one is set.
            if ($this->isConfigured() || $this->databaseNotificationsPolling !== null) {
                $panel->databaseNotificationsPolling($this->databaseNotificationsPolling);
            }
        }

        if ($this->hasChannelsWidget) {
            $panel->widgets([HibiraChannelsWidget::class]);
        }

        // Channels are authorized on /broadcasting/auth, outside the panel,
        // so they are registered for every request. After boot, so the
        // hibira broadcaster exists.
        app()->booted(function () use ($panel) {
            Presence::registerChannels($panel->getId(), $panel->getAuthGuard());
        });
    }

    public function boot(Panel $panel): void
    {
        // Booted only for the panel being served, so other panels keep their
        // own Echo settings.
        if (! $this->isConfigured()) {
            return;
        }

        config(['filament.broadcasting.echo' => $this->getEchoConfig()]);

        if ($this->hasConnectionStatus) {
            FilamentView::registerRenderHook(
                $this->connectionStatusHook,
                fn (): string => view('hibira-filament::connection-status')->render(),
            );
        }

        if ($this->hasPresence) {
            FilamentView::registerRenderHook(
                $this->presenceHook,
                fn (): string => Filament::auth()->check()
                    ? view('hibira-filament::presence-avatars', [
                        'panel' => $panel->getId(),
                        'me' => Filament::auth()->id(),
                        'max' => 5,
                    ])->render()
                    : '',
            );
        }

        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_START, function (): string {
            $page = Livewire::current();

            if (! $page || ! in_array(InteractsWithHibiraLock::class, class_uses_recursive($page), true)) {
                return '';
            }

            return view('hibira-filament::lock-banner', [
                'channel' => $page->getHibiraLockChannel(),
                'me' => (string) Filament::auth()->id(),
                'holder' => $page->hibiraLockHolder,
                'holds' => $page->holdsHibiraLock(),
            ])->render();
        });
    }

    /** @return array<string, mixed> */
    public function getEchoConfig(): array
    {
        $config = config('hibira');
        $scheme = $config['scheme'] ?? 'https';
        $port = (int) ($config['port'] ?? ($scheme === 'https' ? 443 : 80));

        return array_replace([
            'broadcaster' => 'pusher',
            'key' => (string) $config['key'],
            'cluster' => self::CLUSTER,
            'wsHost' => $config['host'] ?? Hibira::DEFAULT_HOST,
            'wsPort' => $port,
            'wssPort' => $port,
            'forceTLS' => $scheme === 'https',
            'enabledTransports' => ['ws', 'wss'],
            'disableStats' => true,
            'authEndpoint' => '/broadcasting/auth',
        ], $this->echoOptions);
    }

    public function isConfigured(): bool
    {
        return filled(config('hibira.key'));
    }
}
