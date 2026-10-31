<?php

namespace Hibira\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Hibira\Filament\Presence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lets one user at a time edit a record. Add it to an EditRecord page.
 *
 * The first user to open the record holds the lock. Everyone on the page
 * joins the lock's presence channel, so when the holder leaves the others
 * claim the lock straight away; the server checks Hibira's member list
 * before handing it over, so there is no heartbeat to poll.
 */
trait InteractsWithHibiraLock
{
    /** @var array{id: string, name: string, avatar?: string, agent?: true}|null */
    public ?array $hibiraLockHolder = null;

    public function mountInteractsWithHibiraLock(): void
    {
        $this->claimHibiraLock();
    }

    /** Takes the lock if it is free, already ours, or its holder has left. */
    public function claimHibiraLock(): void
    {
        $key = $this->getHibiraLockCacheKey();
        $me = $this->getHibiraLockMe();
        $previous = $this->hibiraLockHolder;

        Cache::lock($key.':claim', 5)->block(5, function () use ($key, $me) {
            $holder = Cache::get($key);

            if ($holder === null || $holder['id'] === $me['id'] || ! $this->isPresentInHibiraLock($holder['id'])) {
                $holder = $me;
                Cache::put($key, $holder, $this->getHibiraLockTtl());
            }

            $this->hibiraLockHolder = $holder;
        });

        // Someone else held the record while this page was open, and may
        // have changed it, so show their changes before ours can be saved.
        if ($previous !== null && $previous['id'] !== $me['id'] && $this->holdsHibiraLock()) {
            $this->getRecord()->refresh();
            $this->fillForm();

            Notification::make()
                ->title(__('hibira-filament::lock.reloaded'))
                ->info()
                ->send();
        }
    }

    /** Asks the holder to hand the record over. */
    public function requestHibiraLock(): void
    {
        $this->triggerHibiraLockEvent('hibira-lock-requested', $this->getHibiraLockMe());

        Notification::make()
            ->title(__('hibira-filament::lock.requested', ['name' => $this->hibiraLockHolder['name'] ?? '']))
            ->info()
            ->send();
    }

    /** Saves the holder's changes, then gives the lock to the user who asked. */
    public function handOverHibiraLock(string $userId, string $name): void
    {
        if (! $this->holdsHibiraLock() || ! $this->isPresentInHibiraLock($userId)) {
            return;
        }

        $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

        $holder = Presence::agentsIn($this->getHibiraLockChannel())[$userId] ?? ['id' => $userId, 'name' => $name];
        unset($holder['until']);
        Cache::put($this->getHibiraLockCacheKey(), $holder, $this->getHibiraLockTtl());
        $this->hibiraLockHolder = $holder;

        $this->triggerHibiraLockEvent('hibira-lock-changed', $holder);
    }

    /** Whether an AI agent holds the lock, see Hibira\Filament\Agents\Agent. */
    public function hibiraLockHeldByAgent(): bool
    {
        return (bool) ($this->hibiraLockHolder['agent'] ?? false);
    }

    public function holdsHibiraLock(): bool
    {
        return ($this->hibiraLockHolder['id'] ?? null) === $this->getHibiraLockMe()['id'];
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        // The holder may have changed since the page loaded.
        $this->claimHibiraLock();

        if (! $this->holdsHibiraLock()) {
            Notification::make()
                ->title(__('hibira-filament::lock.save_blocked', ['name' => $this->hibiraLockHolder['name'] ?? '']))
                ->danger()
                ->send();

            return;
        }

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->disabled(fn (): bool => ! $this->holdsHibiraLock());
    }

    public function getHibiraLockChannel(): string
    {
        return Presence::lockChannel(
            Filament::getCurrentPanel()?->getId() ?? 'default',
            static::class.'|'.$this->getRecord()->getKey(),
        );
    }

    protected function getHibiraLockCacheKey(): string
    {
        return Presence::lockCacheKey($this->getHibiraLockChannel());
    }

    /** How long a lock outlives its holder when nobody else comes by. */
    protected function getHibiraLockTtl(): int
    {
        return 60 * 60 * 12;
    }

    /** @return array{id: string, name: string} */
    protected function getHibiraLockMe(): array
    {
        $user = Filament::auth()->user();

        return ['id' => (string) $user->getAuthIdentifier(), 'name' => Filament::getUserName($user)];
    }

    protected function isPresentInHibiraLock(string $userId): bool
    {
        // If Hibira can't be reached, the lock stays where it is.
        return Presence::isHere($this->getHibiraLockChannel(), $userId);
    }

    /** @param  array<string, mixed>  $data */
    protected function triggerHibiraLockEvent(string $event, array $data): void
    {
        try {
            app('hibira')->trigger('presence-'.$this->getHibiraLockChannel(), $event, $data);
        } catch (Throwable $e) {
            Log::warning('Hibira lock: '.$e->getMessage());
        }
    }
}
