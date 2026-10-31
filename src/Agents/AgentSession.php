<?php

namespace Hibira\Filament\Agents;

use Hibira\Filament\Presence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/** An agent's turn at editing one record. */
class AgentSession
{
    protected bool $active = false;

    /**
     * @param  array{id: string, name: string, avatar: string, agent: true}  $member
     * @param  list<string>  $locks  lock channels of the record's edit pages
     * @param  list<string>  $rooms  page rooms of the record's view and edit pages
     */
    public function __construct(
        public readonly array $member,
        protected int $ttl,
        public readonly array $locks,
        public readonly array $rooms,
    ) {}

    /**
     * Joins the record's channels and takes its locks, asking a person who
     * holds one to hand it over and waiting up to $wait seconds.
     *
     * @throws RecordLockedException
     */
    public function begin(int $wait = 0): void
    {
        $this->join();

        $tries = $wait;
        $asked = [];

        try {
            foreach ($this->locks as $channel) {
                while (($holder = $this->claim($channel)) !== null) {
                    if ($tries-- <= 0) {
                        throw new RecordLockedException($holder);
                    }

                    if (! isset($asked[$channel])) {
                        $asked[$channel] = true;
                        $this->trigger([$channel], 'hibira-lock-requested', $this->publicMember());
                    }

                    Sleep::for(1)->second();
                }
            }
        } catch (Throwable $e) {
            $this->stop();

            throw $e;
        }

        $this->active = true;
        $this->trigger($this->locks, 'hibira-lock-changed', ['id' => $this->member['id'], 'name' => $this->member['name']]);
    }

    /** Keeps the agent present for another ttl seconds. Call it during long work. */
    public function touch(): void
    {
        $this->join();

        foreach ($this->locks as $channel) {
            $key = Presence::lockCacheKey($channel);

            if ((Cache::get($key)['id'] ?? null) === $this->member['id']) {
                Cache::put($key, $this->holder(), $this->ttl);
            }
        }
    }

    /** Hands the locks back and leaves the record's pages. */
    public function stop(): void
    {
        $released = [];

        foreach ($this->locks as $channel) {
            $key = Presence::lockCacheKey($channel);

            Cache::lock($key.':claim', 5)->block(5, function () use ($key, $channel, &$released) {
                if ((Cache::get($key)['id'] ?? null) === $this->member['id']) {
                    Cache::forget($key);
                    $released[] = $channel;
                }
            });
        }

        foreach ([...$this->locks, ...$this->rooms] as $channel) {
            Presence::leaveAgent($channel, $this->member['id']);
        }

        $this->trigger([...$this->locks, ...$this->rooms], 'hibira-agent-left', ['id' => $this->member['id']]);

        // Whoever is on the page takes the lock next and reloads the record.
        $this->trigger($released, 'hibira-lock-changed', ['id' => null, 'name' => null]);

        $this->active = false;
    }

    /** Whether the agent holds every lock on the record. */
    public function holdsLock(): bool
    {
        return $this->active;
    }

    protected function join(): void
    {
        $member = $this->publicMember();

        foreach ([...$this->locks, ...$this->rooms] as $channel) {
            Presence::joinAgent($channel, $member);
        }

        $this->trigger([...$this->locks, ...$this->rooms], 'hibira-agent-joined', $member);
    }

    /**
     * Takes the lock if it is free, ours, or its holder has left.
     *
     * @return array{id: string, name: string}|null who holds it instead, or null when we do
     */
    protected function claim(string $channel): ?array
    {
        $key = Presence::lockCacheKey($channel);

        return Cache::lock($key.':claim', 5)->block(5, function () use ($key, $channel) {
            $holder = Cache::get($key);

            if ($holder === null || $holder['id'] === $this->member['id'] || ! Presence::isHere($channel, $holder['id'])) {
                Cache::put($key, $this->holder(), $this->ttl);

                return null;
            }

            return $holder;
        });
    }

    /** @return array{id: string, name: string, avatar: string, agent: true} */
    protected function holder(): array
    {
        return $this->member;
    }

    /** @return array{id: string, name: string, avatar: string, agent: true, until: int} */
    protected function publicMember(): array
    {
        return [...$this->member, 'until' => (now()->getTimestamp() + $this->ttl) * 1000];
    }

    /**
     * @param  list<string>  $channels  Laravel channel names, without the presence- prefix
     * @param  array<string, mixed>  $data
     */
    protected function trigger(array $channels, string $event, array $data): void
    {
        // Hibira takes up to 100 channels per event.
        foreach (array_chunk($channels, 100) as $chunk) {
            try {
                app('hibira')->trigger(array_map(fn (string $c): string => 'presence-'.$c, $chunk), $event, $data);
            } catch (Throwable $e) {
                Log::warning('Hibira agent: '.$e->getMessage());
            }
        }
    }
}
