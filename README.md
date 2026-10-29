# hibira-io/filament

[![ci](https://github.com/hibira-io/filament/actions/workflows/ci.yml/badge.svg)](https://github.com/hibira-io/filament/actions/workflows/ci.yml)

Live notifications for [Filament](https://filamentphp.com) panels, on
[Hibira](https://hibira.io).

Filament can already talk to any Pusher-compatible WebSocket service, once
you publish `config/filament.php`, uncomment its Echo block and fill in the
host, port and key. This plugin does that for you from the same `HIBIRA_*`
settings the rest of your app uses, and adds:

- **Live database notifications.** The bell updates the moment a
  notification is sent, instead of every 30 seconds.
- **A connection light** next to the user menu, so you can see at a glance
  whether the browser is connected.
- **A live channels widget** for the dashboard: channels in use, presence
  channels and the members in them.

Supports Filament 3, 4 and 5. It builds on
[hibira-io/laravel](https://github.com/hibira-io/laravel), which sets up
broadcasting, push notifications and webhooks.

## Install

```sh
composer require hibira-io/filament
php artisan hibira:install
```

`hibira:install` (from hibira-io/laravel) adds the `HIBIRA_*` settings to
`.env` and sets `BROADCAST_CONNECTION=hibira`. Copy the app ID, key and
secret from your app page on [hibira.io/portal](https://hibira.io/portal)
(the free plan needs no card):

```ini
BROADCAST_CONNECTION=hibira
HIBIRA_APP_ID=your-app-id
HIBIRA_APP_KEY=your-app-key
HIBIRA_APP_SECRET=your-app-secret
HIBIRA_HOST=ws.hibira.io
```

Check them with `php artisan hibira:check`.

Then add the plugin to your panel:

```php
use Hibira\Filament\HibiraPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(
            HibiraPlugin::make()
                ->databaseNotifications()
                ->channelsWidget()
        );
}
```

That's it. Filament's own JavaScript already includes Laravel Echo, so there
is nothing to build. You don't need `npm run build` for the panel, and you
don't need to publish `config/filament.php`.

Until `HIBIRA_APP_KEY` is set the plugin stays out of the way: the panel
loads without Echo and database notifications keep polling.

## Live database notifications

`->databaseNotifications()` turns on the panel's notification bell and
turns off its polling. To send a notification that shows up straight away,
pass `isEventDispatched: true` so Filament tells the browser over Hibira:

```php
use Filament\Notifications\Notification;

Notification::make()
    ->title('Order #1024 shipped')
    ->success()
    ->sendToDatabase($user, isEventDispatched: true);
```

Or send a toast that is not stored, to a user who is online now:

```php
Notification::make()
    ->title('Export ready')
    ->broadcast($user);
```

Both go out on the user's private channel, so your app needs the broadcasting
auth route and the channel authorization Laravel adds with
`php artisan install:broadcasting --without-reverb --without-node`
(Laravel 11 and later). `routes/channels.php` then contains:

```php
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
```

If you'd rather keep polling as a fallback, give it an interval:

```php
HibiraPlugin::make()->databaseNotifications(polling: '60s')
```

### Panels with their own guard

Echo authorizes private channels at `/broadcasting/auth`, which uses the
`web` guard by default. If your panel signs users in with another guard,
register the broadcasting routes with that guard, and point Echo at them if
you changed the path:

```php
// bootstrap/app.php
->withBroadcasting(__DIR__.'/../routes/channels.php', [
    'middleware' => ['web', 'auth:admin'],
])
```

```php
HibiraPlugin::make()->echoOptions(['authEndpoint' => '/admin/broadcasting/auth'])
```

## Connection light

A small dot next to the user menu shows the connection: green when
connected, amber while connecting, red when the service can't be reached and
grey when disconnected. Hovering shows the state in words.

Move it or turn it off:

```php
use Filament\View\PanelsRenderHook;

HibiraPlugin::make()->connectionStatus(renderHook: PanelsRenderHook::TOPBAR_END)
HibiraPlugin::make()->connectionStatus(false)
```

## Live channels widget

`->channelsWidget()` adds `HibiraChannelsWidget` to the dashboard. It shows:

- **Live channels**: channels with at least one subscriber
- **Presence channels**: channels that track who is online
- **Presence members**: members across all presence channels (someone in two
  rooms counts twice)

It refreshes every 10 seconds through the Channels HTTP API. API calls don't
count as messages on your plan.

To place it yourself, for example on a custom page or only for some users,
leave `->channelsWidget()` off and register the class like any other widget:

```php
use Hibira\Filament\Widgets\HibiraChannelsWidget;

$panel->widgets([HibiraChannelsWidget::class]);
```

## Echo options

The plugin builds these Echo options from `config/hibira.php`:

| Option | From |
| --- | --- |
| `key` | `HIBIRA_APP_KEY` |
| `wsHost` | `HIBIRA_HOST` (default `ws.hibira.io`) |
| `wsPort`, `wssPort` | `HIBIRA_PORT` (default `443`) |
| `forceTLS` | `HIBIRA_SCHEME` is `https` |
| `cluster` | always `hkg`; pusher-js requires one but ignores it once `wsHost` is set |

Anything else can be added or replaced with `->echoOptions([...])`.

The settings are applied only while the panel with the plugin is being
served, so other panels keep their own `filament.broadcasting.echo` config.

## Translations

The connection light and widget come in English, Traditional Chinese,
Simplified Chinese, Japanese, Korean, Thai and Vietnamese, following the app
locale. To change the wording:

```sh
php artisan vendor:publish --tag=hibira-filament-translations
```

## Development

```sh
composer install
composer test
```

## License

[MIT](LICENSE)
