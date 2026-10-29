<?php

namespace Hibira\Filament;

use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Contracts\HasTable;
use Filament\View\PanelsRenderHook;
use Hibira\Filament\Chat\Chat;
use Hibira\Filament\Concerns\InteractsWithHibiraLock;
use Hibira\Filament\Http\ChatController;
use Hibira\Filament\Pages\Chat as ChatPage;
use Hibira\Filament\Widgets\HibiraChannelsWidget;
use Hibira\Laravel\Hibira;
use Illuminate\Support\Facades\Route;
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

    protected bool $hasLiveTables = false;

    /** @var list<class-string> */
    protected array $liveTableModels = [];

    protected bool $hasImportProgress = false;

    protected bool $hasChat = false;

    protected bool $hasChatNavigation = true;

    protected ?int $chatNavigationSort = null;

    /** @var list<string> */
    protected array $chatSearchColumns = ['name', 'email'];

    protected string $chatButtonHook = PanelsRenderHook::USER_MENU_BEFORE;

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
     * Refreshes resource tables when their records are saved or deleted,
     * for everyone who has them open. Pass more models for tables that list
     * something other than a resource's model.
     *
     * @param  list<class-string>  $models
     */
    public function liveTables(bool $condition = true, array $models = []): static
    {
        $this->hasLiveTables = $condition;
        $this->liveTableModels = $models;

        return $this;
    }

    /** Shows the progress of the user's imports and exports as their rows are processed. */
    public function importProgress(bool $condition = true): static
    {
        $this->hasImportProgress = $condition;

        return $this;
    }

    /**
     * Adds a Chat page where the panel's users talk to each other, one to
     * one or in groups, and a button with the unread count to the top bar.
     * Messages are stored by Hibira's Chat API.
     *
     * @param  list<string>|null  $searchColumns  user columns searched when starting a conversation
     */
    public function chat(
        bool $condition = true,
        bool $navigation = true,
        ?int $navigationSort = null,
        ?array $searchColumns = null,
        ?string $buttonRenderHook = null,
    ): static {
        $this->hasChat = $condition;
        $this->hasChatNavigation = $navigation;
        $this->chatNavigationSort = $navigationSort;
        $this->chatSearchColumns = $searchColumns ?? $this->chatSearchColumns;
        $this->chatButtonHook = $buttonRenderHook ?? $this->chatButtonHook;

        return $this;
    }

    public function hasChat(): bool
    {
        return $this->hasChat;
    }

    public function hasChatNavigation(): bool
    {
        return $this->hasChatNavigation;
    }

    public function getChatNavigationSort(): ?int
    {
        return $this->chatNavigationSort;
    }

    /** @return list<string> */
    public function getChatSearchColumns(): array
    {
        return $this->chatSearchColumns;
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

        if ($this->hasChat) {
            $panel->pages([ChatPage::class]);
            $panel->authenticatedRoutes(function () {
                // Like Laravel's own /broadcasting/user-auth, which pusher-js
                // posts to without a CSRF token.
                Route::post('hibira/user-auth', [ChatController::class, 'userAuth'])
                    ->name('hibira.user-auth')
                    ->withoutMiddleware([
                        'Illuminate\\Foundation\\Http\\Middleware\\VerifyCsrfToken',
                        'Illuminate\\Foundation\\Http\\Middleware\\ValidateCsrfToken',
                        'Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery',
                        'App\\Http\\Middleware\\VerifyCsrfToken',
                    ]);
                Route::get('hibira/chat/unread', [ChatController::class, 'unread'])->name('hibira.chat-unread');
            });
        }

        // Channels are authorized on /broadcasting/auth, outside the panel,
        // so they are registered for every request. After boot, so the
        // hibira broadcaster exists.
        app()->booted(function () use ($panel) {
            Presence::registerChannels($panel->getId(), $panel->getAuthGuard());

            // Records change in queued jobs and other panels too, so the
            // models are watched on every request, not only this panel's.
            if ($this->hasLiveTables) {
                app(LiveTables::class)->watch($panel->getId(), array_unique([
                    ...array_map(fn (string $resource): string => $resource::getModel(), $panel->getResources()),
                    ...$this->liveTableModels,
                ]));
            }

            if ($this->hasImportProgress) {
                app(JobProgress::class)->watch($panel->getId());
            }
        });
    }

    public function boot(Panel $panel): void
    {
        // Booted only for the panel being served, so other panels keep their
        // own Echo settings.
        if (! $this->isConfigured()) {
            return;
        }

        config(['filament.broadcasting.echo' => $this->getEchoConfig($panel)]);

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

        if ($this->hasChat) {
            FilamentView::registerRenderHook($this->chatButtonHook, function () use ($panel): string {
                $user = Filament::auth()->user();
                $chat = Chat::forPanel($panel);

                if (! $user || ! $chat->canChat($user)) {
                    return '';
                }

                return view('hibira-filament::chat-button', [
                    'me' => $chat->userId($user),
                    'url' => ChatPage::getUrl(panel: $panel->getId()),
                    'unreadUrl' => route("filament.{$panel->getId()}.hibira.chat-unread", absolute: false),
                ])->render();
            });
        }

        if ($this->hasLiveTables) {
            FilamentView::registerRenderHook(PanelsRenderHook::PAGE_START, function () use ($panel): string {
                $page = Livewire::current();

                if (! $page instanceof HasTable) {
                    return '';
                }

                $model = $page->getTable()->getModel();

                if (! in_array($model, app(LiveTables::class)->models(), true)) {
                    return '';
                }

                return view('hibira-filament::live-table', [
                    'channel' => Presence::tableChannel($panel->getId(), $model),
                ])->render();
            });
        }

        if ($this->hasImportProgress) {
            FilamentView::registerRenderHook(PanelsRenderHook::BODY_END, function () use ($panel): string {
                $user = Filament::auth()->user();

                if (! $user) {
                    return '';
                }

                return view('hibira-filament::job-progress', [
                    'channel' => Presence::userChannel($panel->getId(), $user->getAuthIdentifier()),
                    'running' => JobProgress::running($user),
                ])->render();
            });
        }
    }

    /** @return array<string, mixed> */
    public function getEchoConfig(?Panel $panel = null): array
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
        ], $this->hasChat && $panel ? [
            'userAuthentication' => [
                'endpoint' => route("filament.{$panel->getId()}.hibira.user-auth", absolute: false),
                'transport' => 'ajax',
                // Laravel Echo adds the CSRF token here.
                'headers' => (object) [],
            ],
        ] : [], $this->echoOptions);
    }

    public function isConfigured(): bool
    {
        return filled(config('hibira.key'));
    }
}
