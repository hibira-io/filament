<?php

namespace Hibira\Filament\Tests;

use Filament\Facades\Filament;
use Hibira\Filament\Presence;
use Hibira\Filament\Tests\Fixtures\EditPost;
use Hibira\Filament\Tests\Fixtures\Post;
use Hibira\Filament\Tests\Fixtures\User;
use Livewire\Livewire;
use Mockery;
use Pusher\Pusher;

class LockTest extends TestCase
{
    protected User $ada;

    protected User $bob;

    protected Post $post;

    /** @var list<string> user IDs Hibira reports in the lock channel */
    protected array $present = [];

    /** @var list<array{0: string, 1: string, 2: array}> */
    protected array $triggered = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $this->bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'x']);
        $this->post = Post::create(['title' => 'Hello']);

        $hibira = Mockery::mock(Pusher::class);
        $hibira->shouldReceive('get')->andReturnUsing(function (string $path) {
            $this->assertMatchesRegularExpression('#^/channels/presence-hibira\.admin\.lock\.[0-9a-f]{24}/users$#', $path);

            return ['users' => array_map(fn ($id) => ['id' => $id], $this->present)];
        });
        $hibira->shouldReceive('trigger')->andReturnUsing(function ($channel, $event, $data) {
            $this->triggered[] = [$channel, $event, $data];

            return true;
        });
        $this->app->instance('hibira', $hibira);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    protected function editAs(User $user)
    {
        $this->actingAs($user);

        return Livewire::test(EditPost::class, ['record' => $this->post->getKey()]);
    }

    public function test_the_first_user_holds_the_lock()
    {
        $this->editAs($this->ada)
            ->assertSet('hibiraLockHolder', ['id' => (string) $this->ada->id, 'name' => 'Ada'])
            ->assertSee('data-hibira-lock', false)
            ->assertDontSee('Ada is editing this record');
    }

    public function test_others_see_who_is_editing_and_cannot_save()
    {
        $this->editAs($this->ada);
        $this->present = [(string) $this->ada->id, (string) $this->bob->id];

        $this->editAs($this->bob)
            ->assertSet('hibiraLockHolder.id', (string) $this->ada->id)
            ->assertSee('Ada is editing this record')
            ->assertSee('Ask to edit')
            ->call('save')
            ->assertNotified('Ada is editing this record, so your changes were not saved.');
    }

    public function test_the_lock_passes_on_when_its_holder_has_left()
    {
        $this->editAs($this->ada);
        $this->present = [(string) $this->bob->id];

        $this->editAs($this->bob)
            ->assertSet('hibiraLockHolder.id', (string) $this->bob->id)
            ->assertDontSee('is editing this record');
    }

    public function test_the_lock_stays_when_hibira_cannot_be_reached()
    {
        $this->editAs($this->ada);

        $hibira = Mockery::mock(Pusher::class);
        $hibira->shouldReceive('get')->andThrow(new \RuntimeException('timeout'));
        $this->app->instance('hibira', $hibira);

        $this->editAs($this->bob)->assertSet('hibiraLockHolder.id', (string) $this->ada->id);
    }

    public function test_asking_for_the_lock_tells_the_holder()
    {
        $this->editAs($this->ada);
        $this->present = [(string) $this->ada->id, (string) $this->bob->id];

        $this->editAs($this->bob)->call('requestHibiraLock');

        [$channel, $event, $data] = end($this->triggered);
        $this->assertStringStartsWith('presence-hibira.admin.lock.', $channel);
        $this->assertSame('hibira-lock-requested', $event);
        $this->assertSame(['id' => (string) $this->bob->id, 'name' => 'Bob'], $data);
    }

    public function test_the_holder_can_hand_over_to_someone_on_the_page()
    {
        $ada = $this->editAs($this->ada);
        $this->present = [(string) $this->ada->id, (string) $this->bob->id];

        $ada->call('handOverHibiraLock', (string) $this->bob->id, 'Bob')
            ->assertSet('hibiraLockHolder.id', (string) $this->bob->id);

        $this->assertSame('hibira-lock-changed', end($this->triggered)[1]);

        $this->editAs($this->bob)->assertSet('hibiraLockHolder.id', (string) $this->bob->id);
    }

    public function test_the_lock_is_not_handed_to_someone_who_left()
    {
        $ada = $this->editAs($this->ada);
        $this->present = [(string) $this->ada->id];

        $ada->call('handOverHibiraLock', (string) $this->bob->id, 'Bob')
            ->assertSet('hibiraLockHolder.id', (string) $this->ada->id);
    }

    public function test_presence_members_must_be_allowed_into_the_panel()
    {
        $this->assertSame(
            ['id' => $this->ada->id, 'name' => 'Ada'],
            array_intersect_key(Presence::member('admin', $this->ada), ['id' => true, 'name' => true]),
        );
        $this->assertArrayHasKey('avatar', Presence::member('admin', $this->ada));
        $this->assertFalse(Presence::member('missing', $this->ada));
    }

    public function test_pages_show_who_else_is_here()
    {
        $this->actingAs($this->ada)
            ->get('/admin/posts')
            ->assertOk()
            ->assertSee('data-hibira-presence', false)
            ->assertSee('hibira.admin.page.', false);
    }
}
