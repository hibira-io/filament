<?php

namespace Hibira\Filament;

use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Channels for "who's here" avatars, record locks, live tables and job
 * progress.
 *
 * Page rooms are named by the browser from the page path, so the server only
 * checks that the user may use the panel. Lock rooms are named by the server.
 */
class Presence
{
    /** @var array<string, true> */
    protected static array $registered = [];

    /** Registers the panel's presence channels once per panel. */
    public static function registerChannels(string $panelId, ?string $guard): void
    {
        if (isset(static::$registered[$panelId])) {
            return;
        }

        static::$registered[$panelId] = true;

        $authorize = function ($user, string $panel) {
            return static::member($panel, $user);
        };

        $options = $guard ? ['guards' => [$guard]] : [];

        Broadcast::channel('hibira.{panel}.page.{room}', $authorize, $options);
        Broadcast::channel('hibira.{panel}.lock.{room}', $authorize, $options);
        Broadcast::channel('hibira.{panel}.table.{model}', $authorize, $options);
        Broadcast::channel('hibira.{panel}.user.{id}', function ($user, string $panel, string $id) {
            return (string) $user->getAuthIdentifier() === $id && static::member($panel, $user) !== false;
        }, $options);
    }

    /**
     * The member info other users see, or false when the user may not use
     * the panel.
     *
     * @return array{id: mixed, name: string, avatar: string}|false
     */
    public static function member(string $panelId, Authenticatable $user): array | false
    {
        $panel = Filament::getPanels()[$panelId] ?? null;

        if ($panel === null) {
            return false;
        }

        // The same rule Filament's own Authenticate middleware applies.
        $allowed = $user instanceof FilamentUser
            ? $user->canAccessPanel($panel)
            : app()->isLocal();

        if (! $allowed) {
            return false;
        }

        return [
            'id' => $user->getAuthIdentifier(),
            'name' => Filament::getUserName($user),
            'avatar' => Filament::getUserAvatarUrl($user),
        ];
    }

    /**
     * Laravel channel name (without the presence- prefix) of a page's room.
     * The same name presence-avatars.blade.php builds in the browser.
     */
    public static function pageChannel(string $panelId, string $path): string
    {
        $hash = 5381;

        // JavaScript adds each code point's first UTF-16 unit.
        foreach (mb_str_split($path) as $char) {
            $code = mb_ord($char);
            $unit = $code > 0xFFFF ? 0xD800 + (($code - 0x10000) >> 10) : $code;
            $hash = (($hash << 5) + $hash + $unit) & 0xFFFFFFFF;
        }

        return "hibira.{$panelId}.page.".base_convert((string) $hash, 10, 36);
    }

    /** Laravel channel name (without the presence- prefix) of a record lock. */
    public static function lockChannel(string $panelId, string $subject): string
    {
        return "hibira.{$panelId}.lock.".substr(sha1($subject), 0, 24);
    }

    /** Laravel channel name (without the private- prefix) told when a model's table changes. */
    public static function tableChannel(string $panelId, string $model): string
    {
        return "hibira.{$panelId}.table.".substr(sha1($model), 0, 24);
    }

    /** Laravel channel name (without the private- prefix) of one user's job progress. */
    public static function userChannel(string $panelId, int | string $userId): string
    {
        return "hibira.{$panelId}.user.{$userId}";
    }

    /**
     * IDs of the users currently in a presence channel, read from Hibira.
     *
     * @return list<string>
     */
    public static function usersIn(string $channel): array
    {
        $users = app('hibira')->get('/channels/presence-'.$channel.'/users', [], true)['users'] ?? [];

        return array_values(array_map(fn (array $user): string => (string) $user['id'], $users));
    }

    /** Cache key that holds a lock's holder. */
    public static function lockCacheKey(string $lockChannel): string
    {
        return 'hibira-filament:'.$lockChannel;
    }

    /**
     * Whether a member is still in a presence channel: an agent that has
     * not timed out, or a user Hibira lists. If Hibira can't be reached,
     * users count as present.
     */
    public static function isHere(string $channel, string $id): bool
    {
        if (str_starts_with($id, 'agent:')) {
            return isset(static::agentsIn($channel)[$id]);
        }

        try {
            return in_array($id, static::usersIn($channel), true);
        } catch (Throwable $e) {
            Log::warning('Hibira presence: '.$e->getMessage());

            return true;
        }
    }

    /**
     * Agents in a channel. Agents can't hold a WebSocket open from a queued
     * job, so they are kept in the cache and announced with events instead.
     *
     * @return array<string, array{id: string, name: string, avatar: string, agent: true, until: int}>
     */
    public static function agentsIn(string $channel): array
    {
        $now = now()->getTimestamp() * 1000;

        return array_filter(
            Cache::get(static::agentsKey($channel), []),
            fn (array $agent): bool => $agent['until'] > $now,
        );
    }

    /** @param  array{id: string, name: string, avatar: string, agent: true, until: int}  $agent */
    public static function joinAgent(string $channel, array $agent): void
    {
        static::updateAgents($channel, fn (array $agents): array => [...$agents, $agent['id'] => $agent]);
    }

    public static function leaveAgent(string $channel, string $id): void
    {
        static::updateAgents($channel, fn (array $agents): array => array_diff_key($agents, [$id => true]));
    }

    /** @param  \Closure(array<string, array>): array<string, array>  $change */
    protected static function updateAgents(string $channel, \Closure $change): void
    {
        $key = static::agentsKey($channel);

        Cache::lock($key.':update', 5)->block(5, function () use ($key, $channel, $change) {
            $agents = $change(static::agentsIn($channel));

            if ($agents === []) {
                Cache::forget($key);

                return;
            }

            $ttl = (int) ceil(max(array_column($agents, 'until')) / 1000) - now()->getTimestamp();
            Cache::put($key, $agents, max($ttl, 1));
        });
    }

    protected static function agentsKey(string $channel): string
    {
        return 'hibira-filament:agents:'.$channel;
    }
}
