<?php

namespace Hibira\Filament;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Updates the notification bell as soon as any database notification is
 * stored, not only the ones sent with isEventDispatched: true.
 *
 * Filament's own DatabaseNotificationsSent event goes out only when asked
 * for, as a second queued job. This sends the same event straight to
 * Hibira when the request or queued job that stored it ends, once per user.
 */
class LiveNotifications
{
    public const EVENT = 'database-notifications.sent';

    protected bool $watching = false;

    /** @var array<string, true> channels to tell */
    protected array $pending = [];

    public function watch(): void
    {
        if ($this->watching) {
            return;
        }

        $this->watching = true;

        Event::listen(NotificationSent::class, function (NotificationSent $event) {
            if ($event->channel === 'database' && $event->notifiable instanceof Model) {
                $this->touch($event->notifiable);
            }
        });

        app()->terminating(fn () => $this->flush());
        Event::listen([JobProcessed::class, JobFailed::class], fn () => $this->flush());
    }

    /** Marks a user's bell as out of date. It is told when the request or job ends. */
    public function touch(Model | Authenticatable $user): void
    {
        $this->pending['private-'.static::channel($user)] = true;
    }

    /** The private channel Filament's bell listens on, without the private- prefix. */
    public static function channel(Model | Authenticatable $user): string
    {
        if (method_exists($user, 'receivesBroadcastNotificationsOn')) {
            return (string) $user->receivesBroadcastNotificationsOn();
        }

        return str_replace('\\', '.', $user::class).'.'.$user->getKey();
    }

    /**
     * Lets each user listen on their own notification channel, for apps
     * whose routes/channels.php doesn't authorize it (for example when the
     * user model isn't App\Models\User). A rule the app defines wins.
     *
     * @param  class-string|null  $model
     */
    public static function authorizeChannel(?string $model, ?string $guard): void
    {
        if ($model === null || ! class_exists($model) || method_exists($model, 'receivesBroadcastNotificationsOn')) {
            return;
        }

        $pattern = str_replace('\\', '.', $model).'.{id}';

        try {
            $broadcaster = Broadcast::driver();

            if (method_exists($broadcaster, 'getChannels') && $broadcaster->getChannels()->has($pattern)) {
                return;
            }

            Broadcast::channel(
                $pattern,
                fn ($user, $id): bool => $user instanceof $model && (string) $user->getAuthIdentifier() === (string) $id,
                $guard ? ['guards' => [$guard]] : [],
            );
        } catch (Throwable $e) {
            Log::warning('Hibira notifications: '.$e->getMessage());
        }
    }

    /** Tells the bells marked so far. */
    public function flush(): void
    {
        $channels = array_keys($this->pending);
        $this->pending = [];

        foreach (array_chunk($channels, 100) as $chunk) {
            try {
                app('hibira')->trigger($chunk, static::EVENT, []);
            } catch (Throwable $e) {
                Log::warning('Hibira notifications: '.$e->getMessage());
            }
        }
    }
}
