<?php

namespace Hibira\Filament\Tests;

use Hibira\Filament\LiveRecords;
use Hibira\Filament\Presence;
use Hibira\Filament\Tests\Fixtures\Post;
use Hibira\Filament\Tests\Fixtures\User;
use Mockery;
use Pusher\Pusher;

class LiveRecordsTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: array}> */
    protected array $triggered = [];

    protected function setUp(): void
    {
        parent::setUp();

        $hibira = Mockery::mock(Pusher::class);
        $hibira->shouldReceive('trigger')->andReturnUsing(function ($channel, $event, $data) {
            $this->triggered[] = [$channel, $event, $data];

            return true;
        });
        $this->app->instance('hibira', $hibira);
    }

    public function test_changes_are_sent_once_with_attribute_names_and_who_made_them()
    {
        $post = Post::create(['title' => 'One']);
        $ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $this->actingAs($ada);

        $post->update(['title' => 'Two']);
        $post->update(['title' => 'Three']);

        $this->assertSame([], $this->triggered, 'nothing is sent before the request ends');

        app(LiveRecords::class)->flush();

        $this->assertCount(1, $this->triggered);
        [$channel, $event, $data] = $this->triggered[0];

        $this->assertSame('private-'.Presence::recordChannel('admin', $post), $channel);
        $this->assertSame('hibira-record-changed', $event);
        $this->assertContains('title', $data['attributes']);
        $this->assertFalse($data['deleted']);
        $this->assertSame(['id' => (string) $ada->id, 'name' => 'Ada'], $data['by']);
        $this->assertStringNotContainsString('Three', json_encode($data), 'values are never sent');
    }

    public function test_creating_a_record_sends_nothing_and_deleting_says_so()
    {
        $post = Post::create(['title' => 'One']);
        app(LiveRecords::class)->flush();
        $this->assertSame([], $this->triggered);

        $post->delete();
        app(LiveRecords::class)->flush();

        $this->assertTrue($this->triggered[0][2]['deleted']);
        $this->assertNull($this->triggered[0][2]['by']);
    }

    public function test_view_pages_listen_and_locked_edit_pages_do_not()
    {
        $ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $post = Post::create(['title' => 'Hello']);

        $this->actingAs($ada)
            ->get("/admin/posts/{$post->id}")
            ->assertOk()
            ->assertSee('data-hibira-live-record', false)
            ->assertSee(Presence::recordChannel('admin', $post), false);

        // The edit page has an edit lock, which keeps it up to date already.
        $this->actingAs($ada)
            ->get("/admin/posts/{$post->id}/edit")
            ->assertOk()
            ->assertDontSee('data-hibira-live-record', false);

        $this->actingAs($ada)
            ->get('/admin/posts')
            ->assertOk()
            ->assertDontSee('data-hibira-live-record', false);
    }
}
