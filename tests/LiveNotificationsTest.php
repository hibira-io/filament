<?php

namespace Hibira\Filament\Tests;

use Filament\Notifications\Notification;
use Hibira\Filament\LiveNotifications;
use Hibira\Filament\Tests\Fixtures\User;
use Illuminate\Support\Facades\Broadcast;
use Mockery;
use Pusher\Pusher;

class LiveNotificationsTest extends TestCase
{
    /** @var list<array{0: array, 1: string}> */
    protected array $triggered = [];

    protected function setUp(): void
    {
        parent::setUp();

        $hibira = Mockery::mock(Pusher::class);
        $hibira->shouldReceive('trigger')->andReturnUsing(function ($channels, $event) {
            $this->triggered[] = [(array) $channels, $event];

            return true;
        });
        $this->app->instance('hibira', $hibira);
    }

    public function test_every_database_notification_updates_the_bell()
    {
        $ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'x']);

        // No isEventDispatched: true, as in most apps. Filament queues its
        // notifications, so each one is a (sync) job that ends with a flush.
        Notification::make()->title('Order shipped')->sendToDatabase($ada);
        Notification::make()->title('Order paid')->sendToDatabase($bob);
        app(LiveNotifications::class)->flush();

        $this->assertSame([
            [['private-Hibira.Filament.Tests.Fixtures.User.'.$ada->id], 'database-notifications.sent'],
            [['private-Hibira.Filament.Tests.Fixtures.User.'.$bob->id], 'database-notifications.sent'],
        ], $this->triggered);
        $this->assertSame(2, $ada->notifications()->count() + $bob->notifications()->count());
    }

    public function test_notifications_in_one_request_update_each_bell_once()
    {
        $ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'x']);

        $ada->notify(new Fixtures\OrderShipped);
        $ada->notify(new Fixtures\OrderShipped);
        $bob->notify(new Fixtures\OrderShipped);

        $this->assertSame([], $this->triggered, 'nothing is sent before the request ends');

        app(LiveNotifications::class)->flush();

        $this->assertSame([[[
            'private-Hibira.Filament.Tests.Fixtures.User.'.$ada->id,
            'private-Hibira.Filament.Tests.Fixtures.User.'.$bob->id,
        ], 'database-notifications.sent']], $this->triggered);
    }

    public function test_the_channel_follows_receives_broadcast_notifications_on()
    {
        $user = new class extends User
        {
            protected $table = 'users';

            public function receivesBroadcastNotificationsOn(): string
            {
                return 'staff.7';
            }
        };

        $this->assertSame('staff.7', LiveNotifications::channel($user));
    }

    public function test_the_users_notification_channel_is_authorized()
    {
        $channels = Broadcast::driver()->getChannels();

        $this->assertTrue($channels->has('Hibira.Filament.Tests.Fixtures.User.{id}'));

        $ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $check = $channels->get('Hibira.Filament.Tests.Fixtures.User.{id}');

        $this->assertTrue($check($ada, (string) $ada->id));
        $this->assertFalse($check($ada, (string) ($ada->id + 1)));
    }
}
