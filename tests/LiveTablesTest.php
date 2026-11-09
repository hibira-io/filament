<?php

namespace Hibira\Filament\Tests;

use Filament\Facades\Filament;
use Hibira\Filament\LiveTables;
use Hibira\Filament\Presence;
use Hibira\Filament\Tests\Fixtures\Comment;
use Hibira\Filament\Tests\Fixtures\CommentsRelationManager;
use Hibira\Filament\Tests\Fixtures\EditPost;
use Hibira\Filament\Tests\Fixtures\Post;
use Hibira\Filament\Tests\Fixtures\PostResource;
use Hibira\Filament\Tests\Fixtures\User;
use Livewire\Livewire;
use Mockery;
use Pusher\Pusher;

class LiveTablesTest extends TestCase
{
    /** @var list<array{0: array, 1: string}> */
    protected array $triggered = [];

    protected function setUp(): void
    {
        parent::setUp();

        $hibira = Mockery::mock(Pusher::class);
        $hibira->shouldReceive('trigger')->andReturnUsing(function ($channels, $event) {
            $this->triggered[] = [$channels, $event];

            return true;
        });
        $this->app->instance('hibira', $hibira);
    }

    public function test_relation_manager_models_are_found()
    {
        $this->assertSame([Comment::class], LiveTables::relatedModels([PostResource::class]));
    }

    public function test_relation_managers_listen_for_changes()
    {
        $post = Post::create(['title' => 'Hello']);
        $this->actingAs(User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();

        Livewire::test(CommentsRelationManager::class, ['ownerRecord' => $post, 'pageClass' => EditPost::class])
            ->assertSee('data-hibira-live-table', false)
            ->assertSee(Presence::tableChannel('admin', Comment::class), false);

        app(LiveTables::class)->flush();
        $this->triggered = [];

        $post->comments()->create(['body' => 'Nice']);
        app(LiveTables::class)->flush();

        $this->assertSame([
            [['private-'.Presence::tableChannel('admin', Comment::class)], 'hibira-table-changed'],
        ], $this->triggered);
    }

    public function test_resource_models_are_watched()
    {
        $this->assertSame([Post::class, Comment::class], app(LiveTables::class)->models());
    }

    public function test_a_burst_of_changes_is_one_message()
    {
        $post = Post::create(['title' => 'One']);
        Post::create(['title' => 'Two']);
        $post->delete();

        $this->assertSame([], $this->triggered, 'nothing is sent before the request ends');

        app(LiveTables::class)->flush();

        $this->assertSame([
            [['private-'.Presence::tableChannel('admin', Post::class)], 'hibira-table-changed'],
        ], $this->triggered);

        app(LiveTables::class)->flush();
        $this->assertCount(1, $this->triggered, 'a second flush has nothing to send');
    }

    public function test_other_models_are_ignored()
    {
        User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        app(LiveTables::class)->flush();

        $this->assertSame([], $this->triggered);
    }

    public function test_query_builder_updates_can_be_marked_by_hand()
    {
        app(LiveTables::class)->touch(Post::class);
        app(LiveTables::class)->flush();

        $this->assertSame('hibira-table-changed', $this->triggered[0][1]);
    }

    public function test_list_pages_listen_for_changes()
    {
        $ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $post = Post::create(['title' => 'Hello']);

        $this->actingAs($ada)
            ->get('/admin/posts')
            ->assertOk()
            ->assertSee('data-hibira-live-table', false)
            ->assertSee(Presence::tableChannel('admin', Post::class), false);

        $this->actingAs($ada)
            ->get("/admin/posts/{$post->id}/edit")
            ->assertOk()
            ->assertDontSee('data-hibira-live-table', false);
    }
}
