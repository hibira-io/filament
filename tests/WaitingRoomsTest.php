<?php

namespace Hibira\Filament\Tests;

use Filament\Facades\Filament;
use Hibira\Filament\Pages\WaitingRooms;
use Hibira\Filament\Tests\Fixtures\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class WaitingRoomsTest extends TestCase
{
    protected User $ada;

    /** @var array<string, array<string, mixed>> rooms Hibira holds */
    protected array $rooms = [];

    protected bool $canList = true;

    protected bool $hasNotices = true;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    protected array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(\Hibira\Laravel\WaitingRooms\WaitingRooms::class)) {
            $this->markTestSkipped('Waiting rooms need hibira-io/laravel 0.4');
        }

        $this->ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $this->rooms['presale'] = $this->roomView('presale', ['title' => 'Winter presale', 'waiting' => 4200, 'admitted' => 1200, 'issued' => 5400]);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $body = $request->body() === '' ? [] : json_decode($request->body(), true);
            $this->calls[] = [$request->method(), $path, $body];

            if ($path === '/apps/1001/waiting_rooms') {
                return $this->canList
                    ? Http::response(['rooms' => array_values($this->rooms)])
                    : Http::response(['error' => 'not found'], 404);
            }
            if (! preg_match('#^/apps/1001/waiting_rooms/([^/]+)(/admit)?$#', $path, $m)) {
                return Http::response(['error' => 'not found'], 404);
            }
            $name = $m[1];
            $room = $this->rooms[$name] ?? null;

            if ($request->method() === 'DELETE') {
                unset($this->rooms[$name]);

                return Http::response(['deleted' => true]);
            }
            if ($request->method() === 'GET') {
                return $room ? Http::response($room) : Http::response(['error' => 'waiting room not found'], 404);
            }
            if (($m[2] ?? '') === '/admit') {
                $this->rooms[$name]['admitted'] += $body['count'];

                return Http::response($this->rooms[$name]);
            }
            if (isset($body['notice']) && ! $this->hasNotices) {
                return Http::response(['error' => 'invalid body: json: unknown field "notice"'], 400);
            }
            $this->rooms[$name] = array_merge($room ?? $this->roomView($name), $body);

            return Http::response($this->rooms[$name], $room ? 200 : 201);
        });

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->actingAs($this->ada);
    }

    protected function roomView(string $name, array $fields = []): array
    {
        return array_merge([
            'room' => $name, 'title' => '', 'state' => 'open', 'admitted' => 0, 'issued' => 0, 'waiting' => 0,
            'admit_per_minute' => 300, 'channel' => 'cache-waitroom-'.$name, 'burst' => 50, 'pass_ttl_seconds' => 1800,
            'return_urls' => ['https://shop.test/drop'],
        ], $fields);
    }

    public function test_lists_rooms_with_live_counters(): void
    {
        $this->get(WaitingRooms::getUrl())
            ->assertOk()
            ->assertSee('Winter presale')
            ->assertSee('4,200')
            ->assertSee('#1,200')
            ->assertSee('cache-waitroom-presale', false)
            ->assertSee('https://ws.hibira.io/wait/pk_test/presale?return=https%3A%2F%2Fshop.test%2Fdrop', false);
    }

    public function test_falls_back_to_the_named_rooms_on_older_servers(): void
    {
        $this->canList = false;
        $this->rooms['other'] = $this->roomView('other', ['title' => 'Not named in the plugin']);

        $this->get(WaitingRooms::getUrl())
            ->assertOk()
            ->assertSee('Winter presale')
            ->assertDontSee('Not named in the plugin');
    }

    public function test_pause_open_admit_and_close(): void
    {
        Livewire::test(WaitingRooms::class)
            ->callAction('pause', arguments: ['room' => 'presale'])
            ->assertNotified('"presale" is paused. The line keeps its places.');
        $this->assertSame('paused', $this->rooms['presale']['state']);

        Livewire::test(WaitingRooms::class)
            ->callAction('admit', data: ['count' => 500], arguments: ['room' => 'presale']);
        $this->assertSame(1700, $this->rooms['presale']['admitted']);

        Livewire::test(WaitingRooms::class)->callAction('open', arguments: ['room' => 'presale']);
        $this->assertSame('open', $this->rooms['presale']['state']);

        Livewire::test(WaitingRooms::class)->callAction('close', arguments: ['room' => 'presale']);
        $this->assertSame('closed', $this->rooms['presale']['state']);
    }

    public function test_posts_and_removes_a_notice(): void
    {
        Livewire::test(WaitingRooms::class)
            ->callAction('notice', data: ['notice' => 'Restock at 3pm'], arguments: ['room' => 'presale'])
            ->assertNotified('Notice posted');
        $this->assertSame('Restock at 3pm', $this->rooms['presale']['notice']);

        $this->get(WaitingRooms::getUrl())->assertSee('Restock at 3pm');

        Livewire::test(WaitingRooms::class)
            ->callAction('notice', data: ['notice' => ''], arguments: ['room' => 'presale'])
            ->assertNotified('Notice removed');
        $this->assertSame('', $this->rooms['presale']['notice']);
    }

    public function test_says_when_the_server_has_no_notices_yet(): void
    {
        $this->hasNotices = false;

        Livewire::test(WaitingRooms::class)
            ->callAction('notice', data: ['notice' => 'Restock at 3pm'], arguments: ['room' => 'presale'])
            ->assertNotified('This Hibira server does not show notices yet');
    }

    public function test_creates_edits_and_deletes_a_room(): void
    {
        Livewire::test(WaitingRooms::class)
            ->callAction('createRoom', data: [
                'room' => 'launch', 'title' => 'Launch day', 'admit_per_minute' => 120, 'return_url' => 'https://shop.test/launch',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Waiting room "launch" is ready');
        $create = array_values(array_filter($this->calls, fn ($c) => $c[0] === 'POST' && $c[1] === '/apps/1001/waiting_rooms/launch'));
        $this->assertSame(['admit_per_minute' => 120, 'title' => 'Launch day', 'return_urls' => ['https://shop.test/launch']], $create[0][2]);

        Livewire::test(WaitingRooms::class)
            ->callAction('createRoom', data: ['room' => 'no spaces', 'admit_per_minute' => 1, 'return_url' => 'https://shop.test/'])
            ->assertHasActionErrors(['room']);

        Livewire::test(WaitingRooms::class)
            ->callAction('edit', data: ['admit_per_minute' => 600, 'starts_at' => '2026-12-04 12:00:00'], arguments: ['room' => 'launch']);
        $this->assertSame(600, $this->rooms['launch']['admit_per_minute']);
        $this->assertSame('2026-12-04T12:00:00+00:00', $this->rooms['launch']['starts_at']);

        Livewire::test(WaitingRooms::class)->callAction('delete', arguments: ['room' => 'launch']);
        $this->assertArrayNotHasKey('launch', $this->rooms);
    }

    public function test_shows_the_server_error(): void
    {
        Livewire::test(WaitingRooms::class)
            ->callAction('admit', data: ['count' => 5], arguments: ['room' => 'gone'])
            ->assertNotified('Hibira refused the change');
    }

    public function test_only_allowed_users_see_the_page(): void
    {
        $this->actingAs(User::create(['name' => 'Eve', 'email' => 'blocked@example.com', 'password' => 'x']));

        $this->get(WaitingRooms::getUrl())->assertForbidden();
    }
}
