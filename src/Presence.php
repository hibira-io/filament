<?php

namespace Hibira\Filament;

use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;

/**
 * Presence channels for "who's here" avatars and record locks.
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

    /** Laravel channel name (without the presence- prefix) of a record lock. */
    public static function lockChannel(string $panelId, string $subject): string
    {
        return "hibira.{$panelId}.lock.".substr(sha1($subject), 0, 24);
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
}
